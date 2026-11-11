<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/src/Db.php";
require_once dirname(__DIR__, 2) . "/src/QuoteRepository.php";
require_once dirname(__DIR__, 2) . "/src/HouseRepository.php";
require_once dirname(__DIR__, 2) . "/src/helpers/ical.php";

final class QuoteRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv("APP_ENV=testing");
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        QuoteRepository::ensureTable();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testCheckAvailabilityReturnsTrueWhenNoBlocks(): void
    {
        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $propId);

        $avail = QuoteRepository::checkAvailability($propId, "2027-01-10", "2027-01-15");
        $this->assertTrue($avail["available"]);
        $this->assertNull($avail["conflict_date"]);
    }

    public function testCheckAvailabilityDetectsBlockedDays(): void
    {
        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $propId);

        $stmt = $this->pdo->prepare("INSERT INTO calendar_days (property_id, day, blocked, origin) VALUES (?, ?, 1, ?)
                                      ON CONFLICT (property_id, day) DO UPDATE SET blocked = 1, origin = EXCLUDED.origin");
        $stmt->execute([$propId, "2027-01-12", "sync"]);

        $avail = QuoteRepository::checkAvailability($propId, "2027-01-10", "2027-01-15");
        $this->assertFalse($avail["available"]);
        $this->assertSame("2027-01-12", $avail["conflict_date"]);
    }

    public function testCreatesQuoteAndRetrievesByHash(): void
    {
        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $propId);

        $quote = QuoteRepository::create(
            propertyId: $propId,
            checkin: "2027-02-01",
            checkout: "2027-02-04",
            taxPercent: 0,
            specialOffer: 999,
            pixKey: "rafaelrieiro@gmail.com"
        );

        $this->assertNotEmpty($quote["quote_hash"]);
        $this->assertSame(999, $quote["total"]);
        $this->assertSame(3, $quote["nights"]);

        $found = QuoteRepository::findByHash($quote["quote_hash"]);
        $this->assertNotNull($found);
        $this->assertSame($quote["quote_hash"], $found["quote_hash"]);
        $this->assertSame("pending", $found["status"]);
        $this->assertSame("rafaelrieiro@gmail.com", $found["pix_key"]);
    }

    public function testConfirmPaymentLocksCalendarDaysWithOriginReserva(): void
    {
        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $propId);

        $quote = QuoteRepository::create(
            propertyId: $propId,
            checkin: "2027-03-10",
            checkout: "2027-03-12",
            specialOffer: 600
        );

        $ok = QuoteRepository::confirmPayment($quote["quote_hash"]);
        $this->assertTrue($ok);

        $found = QuoteRepository::findByHash($quote["quote_hash"]);
        $this->assertSame("confirmed", $found["status"]);

        $stmt = $this->pdo->prepare("SELECT day, blocked, origin FROM calendar_days WHERE property_id = ? AND day IN (?, ?) ORDER BY day");
        $stmt->execute([$propId, "2027-03-10", "2027-03-11"]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame("reserva", $rows[0]["origin"]);
        $this->assertSame(1, (int) $rows[0]["blocked"]);
        $this->assertSame("reserva", $rows[1]["origin"]);
        $this->assertSame(1, (int) $rows[1]["blocked"]);
    }

    public function testConfirmPaymentUpdatesIcalFeedToLockoutExternalPlatforms(): void
    {
        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $property = HouseRepository::find($propId);

        $quote = QuoteRepository::create(
            propertyId: $propId,
            checkin: "2027-04-15",
            checkout: "2027-04-18",
            specialOffer: 750
        );

        QuoteRepository::confirmPayment($quote["quote_hash"]);

        $ics = export_ics($property);
        $this->assertStringContainsString("BEGIN:VEVENT", $ics);
        $this->assertStringContainsString("SUMMARY:Reservado", $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20270415", $ics);
    }
}
