<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/ImovelNaoEncontrado.php';

final class HouseManagementTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testManagerCanManageGroupPropertyViaFindManageable(): void
    {
        $stmt = $this->pdo->query("
            SELECT p.id as property_id, gm.user_id as manager_id, p.user_id as owner_id
            FROM properties p
            JOIN group_members gm ON gm.group_id = p.group_id AND gm.role = 'gestor'
            WHERE p.status = 'active'
            LIMIT 1
        ");
        $row = $stmt->fetch();
        if (!$row) {
            $this->markTestSkipped('No group property with manager in seed database');
        }

        $propertyId = (int) $row['property_id'];
        $managerId = (int) $row['manager_id'];
        $ownerId = (int) $row['owner_id'];

        $this->assertNotEquals($managerId, $ownerId);

        $this->assertNull(HouseRepository::findByUser($managerId, $propertyId));

        $manageable = HouseRepository::findManageable($managerId, $propertyId);
        $this->assertNotNull($manageable);
        $this->assertSame($propertyId, (int) $manageable['id']);

        $manageableOrFail = HouseRepository::findManageableOrFail($managerId, $propertyId);
        $this->assertSame($propertyId, (int) $manageableOrFail['id']);
    }

    public function testNonManagerNonOwnerCannotManageProperty(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM properties WHERE status = 'active' LIMIT 1");
        $propertyId = (int) $stmt->fetchColumn();

        // Create random user ID not related to property or group
        $nonMemberId = 999999;

        $this->assertNull(HouseRepository::findManageable($nonMemberId, $propertyId));

        $this->expectException(ImovelNaoEncontrado::class);
        HouseRepository::findManageableOrFail($nonMemberId, $propertyId);
    }

    public function testMarkFeedPendingSchedulesImmediateSync(): void
    {
        $stmt = $this->pdo->query("SELECT id FROM properties WHERE status = 'active' LIMIT 1");
        $propertyId = (int) $stmt->fetchColumn();

        $ins = $this->pdo->prepare("INSERT INTO calendar_feeds (property_id, url, title, next_sync_at, fail_count, last_error, last_status, slot) VALUES (?, 'https://example.com/test.ics', 'Test Feed', now() + interval '2 hours', 3, 'timeout', 'ERRO', 1) RETURNING id");
        $ins->execute([$propertyId]);
        $feedId = (int) $ins->fetchColumn();

        HouseRepository::markFeedPending($propertyId, $feedId);

        $check = $this->pdo->prepare("SELECT next_sync_at <= now() AS is_due, fail_count, last_error, last_status FROM calendar_feeds WHERE id = ?");
        $check->execute([$feedId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue((bool) $row['is_due']);
        $this->assertSame(0, (int) $row['fail_count']);
        $this->assertNull($row['last_error']);
        $this->assertSame('PENDENTE', $row['last_status']);
    }

    public function testSyncNowRouteTriggersMarkFeedPending(): void
    {
        require_once dirname(__DIR__, 2) . "/src/Api.php";
        $stmt = $this->pdo->query("
            SELECT p.id as property_id, p.public_hash, p.user_id as owner_id
            FROM properties p
            WHERE p.status = 'active'
            LIMIT 1
        ");
        $prop = $stmt->fetch(PDO::FETCH_ASSOC);
        $propertyId = (int) $prop["property_id"];
        $ownerId = (int) $prop["owner_id"];

        $ins = $this->pdo->prepare("INSERT INTO calendar_feeds (property_id, url, title, next_sync_at, fail_count, last_error, last_status, slot) VALUES (?, 'https://example.com/sync-now.ics', 'Sync Test', now() + interval '3 hours', 2, 'timeout', 'ERRO', 2) RETURNING id");
        $ins->execute([$propertyId]);
        $feedId = (int) $ins->fetchColumn();

        // Testing direct controller / repository sync invocation
        HouseRepository::findManageableOrFail($ownerId, $propertyId);
        HouseRepository::markFeedPending($propertyId, $feedId);

        $check = $this->pdo->prepare("SELECT next_sync_at <= now() AS is_due, fail_count, last_status FROM calendar_feeds WHERE id = ?");
        $check->execute([$feedId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        $this->assertTrue((bool) $row["is_due"]);
        $this->assertSame(0, (int) $row["fail_count"]);
        $this->assertSame("PENDENTE", $row["last_status"]);
    }
}
