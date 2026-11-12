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

    public function testListQuotesForAdminManagement(): void
    {
        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $quote = QuoteRepository::create(
            propertyId: $propId,
            checkin: "2027-06-01",
            checkout: "2027-06-04",
            specialOffer: 450
        );

        $pending = QuoteRepository::listAll();
        $this->assertNotEmpty($pending);
        $hashes = array_column($pending, 'quote_hash');
        $this->assertContains($quote['quote_hash'], $hashes);

        $ok = QuoteRepository::confirmPayment($quote['quote_hash']);
        $this->assertTrue($ok);

        $confirmed = QuoteRepository::listAll('confirmed');
        $hashesConfirmed = array_column($confirmed, 'quote_hash');
        $this->assertContains($quote['quote_hash'], $hashesConfirmed);
    }

    public function testAdminConfirmReservationAndFilterStatus(): void
    {
        $this->assertFalse(QuoteRepository::confirmPayment("nonexistent_hash_123"));

        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $quote = QuoteRepository::create(
            propertyId: $propId,
            checkin: "2027-07-10",
            checkout: "2027-07-13",
            specialOffer: 600
        );

        $pendingList = QuoteRepository::listAll("pending");
        $this->assertContains($quote["quote_hash"], array_column($pendingList, "quote_hash"));

        $confirmedListBefore = QuoteRepository::listAll("confirmed");
        $this->assertNotContains($quote["quote_hash"], array_column($confirmedListBefore, "quote_hash"));

        $success = QuoteRepository::confirmPayment($quote["quote_hash"]);
        $this->assertTrue($success);

        $pendingListAfter = QuoteRepository::listAll("pending");
        $this->assertNotContains($quote["quote_hash"], array_column($pendingListAfter, "quote_hash"));

        $confirmedListAfter = QuoteRepository::listAll("confirmed");
        $this->assertContains($quote["quote_hash"], array_column($confirmedListAfter, "quote_hash"));
    }

    public function testQuoteUsesPropertyPixKeyByDefault(): void
    {
        $propId = (int) $this->pdo->query("SELECT id FROM properties LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $propId);

        $this->pdo->prepare("UPDATE properties SET pix_key = 'custom.pix@casalivre.test' WHERE id = ?")->execute([$propId]);

        $quote = QuoteRepository::create(
            propertyId: $propId,
            checkin: "2027-08-01",
            checkout: "2027-08-03"
        );

        $this->assertSame("custom.pix@casalivre.test", $quote["pix_key"]);
        $found = QuoteRepository::findByHash($quote["quote_hash"]);
        $this->assertSame("custom.pix@casalivre.test", $found["pix_key"]);
    }

    public function testListForUserFiltersByOwnerOrGroupManager(): void
    {
        $prop = $this->pdo->query("SELECT id, user_id FROM properties LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $propId = (int) $prop["id"];
        $ownerId = (int) $prop["user_id"];

        $quote = QuoteRepository::create(
            propertyId: $propId,
            checkin: "2027-08-10",
            checkout: "2027-08-12"
        );

        $ownerQuotes = QuoteRepository::listForUser($ownerId);
        $this->assertContains($quote["quote_hash"], array_column($ownerQuotes, "quote_hash"));

        $otherUserQuotes = QuoteRepository::listForUser(99999999);
        $this->assertNotContains($quote["quote_hash"], array_column($otherUserQuotes, "quote_hash"));
    }

    public function testPlaceOptionTextHidesCidadeForCity(): void
    {
        require_once dirname(__DIR__, 2) . "/src/I18n.php";
        require_once dirname(__DIR__, 2) . "/src/helpers/geo.php";
        $cityOption = ['id' => 10, 'name' => 'Aldeias Altas', 'type' => 'city', 'uf' => 'MA'];
        $text = place_option_text($cityOption);
        $this->assertSame('Aldeias Altas · MA', $text);
        $this->assertStringNotContainsString('Cidade', $text);

        $nbhdOption = ['id' => 20, 'name' => 'Centro', 'type' => 'neighborhood', 'uf' => 'MA', 'parent' => 'Aldeias Altas'];
        $nbhdText = place_option_text($nbhdOption);
        $this->assertStringContainsString('Bairro', $nbhdText);
    }

    public function testQuoteGeneratorCalendarTranslations(): void
    {
        require_once dirname(__DIR__, 2) . "/src/I18n.php";
        I18n::clearCache();
        $_SESSION["locale"] = "pt-BR";
        $this->assertSame("Gerar link de pagamento", I18n::t("cal.generate_quote"));

        $_SESSION["locale"] = "en";
        $this->assertSame("Generate payment link", I18n::t("cal.generate_quote"));

        $_SESSION["locale"] = "es";
        $this->assertSame("Generar enlace de pago", I18n::t("cal.generate_quote"));
        unset($_SESSION["locale"]);
    }

    public function testApiOwnedPropertyAllowsManager(): void
    {
        require_once dirname(__DIR__, 2) . "/src/Api.php";
        $prop = $this->pdo->query("SELECT id, user_id, public_hash FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);
        $ownerId = (int) $prop["user_id"];

        $managerId = (int) $this->pdo->query("SELECT id FROM users WHERE id != " . $ownerId . " LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $managerId);

        $groupId = (int) $this->pdo->query("INSERT INTO groups (name, slug, theme, created_at) VALUES ('Test Group', 'test-group-' || substr(md5(random()::text), 1, 8), 'padrao', CURRENT_TIMESTAMP) RETURNING id")->fetchColumn();
        $this->pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'gestor')")->execute([$groupId, $managerId]);
        $this->pdo->prepare("UPDATE properties SET group_id = ? WHERE id = ?")->execute([$groupId, (int) $prop["id"]]);

        $result = api_owned_property(["id" => $managerId], $prop["public_hash"]);
        $this->assertSame((int) $prop["id"], (int) $result["id"]);
    }

    public function testPlaceOptionTextHidesPontoDeInteresseForPoi(): void
    {
        require_once dirname(__DIR__, 2) . "/src/I18n.php";
        require_once dirname(__DIR__, 2) . "/src/helpers/geo.php";
        $poiOption = [
            "id" => 71607,
            "name" => "Praia de Botafogo",
            "type" => "poi",
            "uf" => "RJ",
            "parent" => "Rio de Janeiro",
        ];
        $text = place_option_text($poiOption);
        $this->assertStringNotContainsString("Ponto de interesse", $text);
        $this->assertSame("Praia de Botafogo · RJ", $text);
    }
}
