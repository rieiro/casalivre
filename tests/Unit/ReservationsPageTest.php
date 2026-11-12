<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/QuoteRepository.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/PhotoRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class ReservationsPageTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
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

    public function testReservationsViewRendersHeaderFiltersAndCards(): void
    {
        $prop = $this->pdo->query("SELECT id, title, public_hash FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);
        $propId = (int) $prop['id'];

        $quotePending = QuoteRepository::create(
            propertyId: $propId,
            checkin: '2027-10-10',
            checkout: '2027-10-13',
            taxPercent: 0,
            specialOffer: 500
        );

        $quoteConfirmed = QuoteRepository::create(
            propertyId: $propId,
            checkin: '2027-10-15',
            checkout: '2027-10-18',
            taxPercent: 0,
            specialOffer: 700
        );
        QuoteRepository::confirmPayment($quoteConfirmed['quote_hash']);

        $quotes = [
            array_merge($quoteConfirmed, ['property_title' => $prop['title'], 'property_hash' => $prop['public_hash'], 'status' => 'confirmed']),
            array_merge($quotePending, ['property_title' => $prop['title'], 'property_hash' => $prop['public_hash'], 'status' => 'pending']),
        ];
        $photos = PhotoRepository::primaryPaths([$propId]);

        ob_start();
        $csrf = 'csrf_token_test';
        $filter = null;
        $confirmed = false;
        $error = null;
        require dirname(__DIR__, 2) . '/views/reservations.php';
        $html = (string) ob_get_clean();

        // Must display header and filter tabs
        $this->assertStringContainsString('res-header', $html);
        $this->assertStringContainsString('res-filter-pill', $html);
        $this->assertStringContainsString('Todas', $html);
        $this->assertStringContainsString('Pendentes', $html);
        $this->assertStringContainsString('Confirmadas', $html);

        // Must display cards with property title
        $this->assertStringContainsString($prop['title'], $html);
        $this->assertStringContainsString('res-card', $html);
        $this->assertStringContainsString('res-dates', $html);
        $this->assertStringContainsString('res-price', $html);

        // Actions
        $this->assertStringContainsString('Confirmar', $html);
        $this->assertStringContainsString('pagamento/' . $quotePending['quote_hash'], $html);
        $this->assertStringContainsString('calendario', $html);
    }

    public function testReservationsViewRendersSuccessBanner(): void
    {
        ob_start();
        $quotes = [];
        $photos = [];
        $filter = null;
        $confirmed = true;
        $error = null;
        $csrf = "csrf_token_test";
        require dirname(__DIR__, 2) . "/views/reservations.php";
        $html = (string) ob_get_clean();

        $this->assertStringContainsString("res-alert-success", $html);
        $this->assertStringContainsString("Confirmado com Sucesso", $html);
    }

    public function testReservationsViewRendersEmptyState(): void
    {
        ob_start();
        $quotes = [];
        $photos = [];
        $filter = "pending";
        $confirmed = false;
        $error = null;
        $csrf = "csrf_token_test";
        require dirname(__DIR__, 2) . "/views/reservations.php";
        $html = (string) ob_get_clean();

        $this->assertStringContainsString("res-empty-box", $html);
        $this->assertStringContainsString("Não há nenhuma reserva aguardando depósito Pix", $html);
    }
}
