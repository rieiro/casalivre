<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/ActivityLog.php';
require_once dirname(__DIR__, 2) . '/src/QuoteRepository.php';

final class QuoteAuditLogTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        QuoteRepository::ensureTable();
        ActivityLog::seedActions($this->pdo);
    }

    protected function tearDown(): void
    {
        try {
            $this->pdo->exec("RESET ROLE");
        } catch (Throwable) {
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        unset($_SESSION['user_id']);
    }

    public function testQuoteCatalogAndClassesIntegrity(): void
    {
        $this->assertSame(51, ActivityLog::QUOTE_CREATE);
        $this->assertSame(52, ActivityLog::RESERVATION_CONFIRM);

        $this->assertArrayHasKey(ActivityLog::QUOTE_CREATE, ActivityLog::CATALOG);
        $this->assertArrayHasKey(ActivityLog::RESERVATION_CONFIRM, ActivityLog::CATALOG);

        $this->assertSame('admin', ActivityLog::classOf(ActivityLog::QUOTE_CREATE));
        $this->assertSame('admin', ActivityLog::classOf(ActivityLog::RESERVATION_CONFIRM));

        $st = $this->pdo->prepare("SELECT code, retention_class FROM activity_actions WHERE code IN (51, 52) ORDER BY code");
        $st->execute();
        $actions = $st->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame('admin', $actions[51] ?? null);
        $this->assertSame('admin', $actions[52] ?? null);
    }

    public function testQuoteCreationLogsAuditRecord(): void
    {
        $prop = $this->pdo->query("SELECT id, user_id FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);
        $propertyId = (int) $prop['id'];
        $userId = (int) $prop['user_id'];

        $_SESSION['user_id'] = $userId;

        $checkin = '2027-04-10';
        $checkout = '2027-04-15';
        $quote = QuoteRepository::create(
            propertyId: $propertyId,
            checkin: $checkin,
            checkout: $checkout,
            taxPercent: 5,
            specialOffer: null,
            pixKey: 'host@casalivre.test'
        );

        $this->assertNotEmpty($quote['quote_hash']);

        activity(
            ActivityLog::QUOTE_CREATE,
            [
                'imovel_id' => $propertyId,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'total' => (int) $quote['total'],
                'pix_key' => 'host@casalivre.test',
            ],
            null,
            [
                'quote_hash' => (string) $quote['quote_hash'],
                'status' => 'pending',
                'total_price' => (int) $quote['total'],
            ]
        );

        $this->pdo->exec("SET ROLE casalivre_retention");
        $st = $this->pdo->prepare("
            SELECT code, retention_class, actor_kind, payload::text as payload, after_value::text as after_value
            FROM audit.audit_log
            WHERE code = 51 AND payload->>'imovel_id' = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $st->execute([(string) $propertyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($row, 'Audit log row for QUOTE_CREATE must be recorded in audit.audit_log');
        $this->assertSame('admin', $row['retention_class']);
        $this->assertSame('user', $row['actor_kind']);

        $payload = json_decode((string) $row['payload'], true);
        $this->assertSame($propertyId, (int) $payload['imovel_id']);
        $this->assertSame($checkin, $payload['checkin']);
        $this->assertSame($checkout, $payload['checkout']);
        $this->assertSame((int) $quote['total'], (int) $payload['total']);

        $after = json_decode((string) $row['after_value'], true);
        $this->assertSame($quote['quote_hash'], $after['quote_hash']);
        $this->assertSame('pending', $after['status']);
    }

    public function testReservationConfirmationLogsBeforeAndAfterAuditValues(): void
    {
        $prop = $this->pdo->query("SELECT id, user_id FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $propertyId = (int) $prop['id'];

        $checkin = '2027-05-10';
        $checkout = '2027-05-13';
        $quote = QuoteRepository::create(
            propertyId: $propertyId,
            checkin: $checkin,
            checkout: $checkout,
            taxPercent: 0,
            specialOffer: null,
            pixKey: 'pix@casalivre.test'
        );

        $hash = (string) $quote['quote_hash'];
        $ok = QuoteRepository::confirmPayment($hash);
        $this->assertTrue($ok);

        // Verify quote status changed in database
        $st = $this->pdo->prepare("SELECT status FROM quotes WHERE quote_hash = ?");
        $st->execute([$hash]);
        $this->assertSame('confirmed', $st->fetchColumn());

        // Verify calendar days are blocked with origin 'reserva'
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM calendar_days WHERE property_id = ? AND day >= ? AND day < ? AND origin = 'reserva' AND blocked = 1");
        $st->execute([$propertyId, $checkin, $checkout]);
        $this->assertSame(3, (int) $st->fetchColumn());

        activity(
            ActivityLog::RESERVATION_CONFIRM,
            [
                'imovel_id' => $propertyId,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'total' => (int) $quote['total'],
                'motivo' => 'admin_confirmado',
            ],
            ['status' => 'pending'],
            ['status' => 'confirmed', 'quote_hash' => $hash]
        );

        $this->pdo->exec("SET ROLE casalivre_retention");
        $st = $this->pdo->prepare("
            SELECT code, retention_class, payload::text as payload, before_value::text as before_value, after_value::text as after_value
            FROM audit.audit_log
            WHERE code = 52 AND payload->>'imovel_id' = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $st->execute([(string) $propertyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($row, 'Audit log row for RESERVATION_CONFIRM must be recorded in audit.audit_log');
        $this->assertSame('admin', $row['retention_class']);

        $before = json_decode((string) $row['before_value'], true);
        $this->assertSame('pending', $before['status']);

        $after = json_decode((string) $row['after_value'], true);
        $this->assertSame('confirmed', $after['status']);
        $this->assertSame($hash, $after['quote_hash']);
    }
}
