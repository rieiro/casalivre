<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/QuoteRepository.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/PhotoRepository.php';
require_once dirname(__DIR__, 2) . '/src/PixPayload.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class PaymentLandingTest extends TestCase
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

    public function testPaymentViewRendersTrustElementsAndBreakdown(): void
    {
        $prop = $this->pdo->query("SELECT id, title, city_place_id, address, max_guests, bedrooms, beds, checkin_time, checkout_time, public_hash FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);

        $quote = QuoteRepository::create(
            propertyId: (int) $prop['id'],
            checkin: '2027-08-10',
            checkout: '2027-08-15',
            taxPercent: 5,
            specialOffer: null,
            pixKey: 'test@casalivre.test'
        );

        $property = HouseRepository::find((int) $prop['id']);
        $photos = PhotoRepository::listReady((int) $prop['id']);
        $property['photo'] = $photos[0]['path'] ?? null;
        $pix_payload = PixPayload::generate(
            key: 'test@casalivre.test',
            name: 'Anfitriao Teste',
            city: 'Sao Paulo',
            amount: (float) $quote['total'],
            txid: 'CL' . strtoupper($quote['quote_hash'])
        );

        ob_start();
        $csrf = 'test_token_123';
        $whatsapp_confirm_url = 'https://wa.me/5521999999999?text=teste';
        $confirmed = false;
        require dirname(__DIR__, 2) . '/views/payment.php';
        $html = (string) ob_get_clean();

        // Must display property details
        $this->assertStringContainsString($prop['title'], $html);
        $this->assertStringContainsString('hold-timer', $html);
        $this->assertStringContainsString('btn-copy-pix', $html);
        $this->assertStringContainsString('pix-payload', $html);

        // Must include trust and confidence markers
        $this->assertStringContainsString('Garantia', $html);
        $this->assertStringContainsString('Segura', $html);
        $this->assertStringContainsString('Reserva Direta', $html);

        // Must display stay breakdown
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('Total', $html);
    }

    public function testPaymentViewRendersConfirmedState(): void
    {
        $prop = $this->pdo->query("SELECT id, title, public_hash FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $quote = QuoteRepository::create(
            propertyId: (int) $prop['id'],
            checkin: '2027-09-01',
            checkout: '2027-09-05',
            specialOffer: 800
        );
        QuoteRepository::confirmPayment($quote['quote_hash']);

        $property = HouseRepository::find((int) $prop['id']);
        $photos = PhotoRepository::listReady((int) $prop['id']);
        $property['photo'] = $photos[0]['path'] ?? null;
        $pixPayload = 'dummy';

        ob_start();
        $csrf = 'test_token';
        $whatsapp_confirm_url = 'https://wa.me/5521999999999?text=ok';
        $confirmed = true;
        require dirname(__DIR__, 2) . '/views/payment.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Confirmado', $html);
        $this->assertStringContainsString($prop['title'], $html);
        $this->assertStringContainsString('WhatsApp', $html);
    }
}
