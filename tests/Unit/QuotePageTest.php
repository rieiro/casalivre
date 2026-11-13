<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/I18n.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/QuoteRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class QuotePageTest extends TestCase
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

    public function testQuotePageRendersLuxuryHeaderAndForm(): void
    {
        $property = [
            'id' => 1,
            'public_hash' => 'abc123def4567890',
            'title' => 'Chalé da Serra',
            'city' => 'Urubici',
            'nightly_price' => 350,
            'cleaning_fee' => 100,
            'pix_key' => 'chale@casalivre.test',
        ];

        ob_start();
        try {
            $error = null;
            $quote = null;
            $payment_url = null;
            $whatsapp_msg = null;
            $posted = [];
            $checkin = '2027-05-10';
            $checkout = '2027-05-13';
            $csrf = 'csrf_token_test';
            require dirname(__DIR__, 2) . '/views/quote.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // Luxury Header & back link
        $this->assertStringContainsString('quote-page', $html);
        $this->assertStringContainsString('quote-header', $html);
        $this->assertStringContainsString('/calendario', $html);
        $this->assertStringContainsString('Chalé da Serra', $html);

        // Form elements
        $this->assertStringContainsString('name="tax_percent"', $html);
        $this->assertStringContainsString('name="special_offer"', $html);
        $this->assertStringContainsString('name="checkin"', $html);
        $this->assertStringContainsString('name="checkout"', $html);
    }

    public function testQuotePageRendersGeneratedLinkCardWhenQuoteExists(): void
    {
        $property = [
            'id' => 1,
            'public_hash' => 'abc123def4567890',
            'title' => 'Chalé da Serra',
            'city' => 'Urubici',
            'nightly_price' => 350,
            'cleaning_fee' => 100,
            'pix_key' => 'chale@casalivre.test',
        ];

        $quote = [
            'quote_hash' => 'hashquote1234567',
            'checkin' => '2027-05-10',
            'checkout' => '2027-05-13',
            'nights' => 3,
            'subtotal' => 1050,
            'cleaning' => 100,
            'taxes' => 0,
            'special_offer' => null,
            'total' => 1150,
        ];

        ob_start();
        try {
            $error = null;
            $payment_url = 'http://casalivre.test/pagamento/hashquote1234567';
            $whatsapp_msg = 'Mensagem de teste';
            $posted = [];
            $checkin = '2027-05-10';
            $checkout = '2027-05-13';
            $csrf = 'csrf_token_test';
            require dirname(__DIR__, 2) . '/views/quote.php';
            $html = (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // Elements required by quote-admin.js
        $this->assertStringContainsString('id="quote-link"', $html);
        $this->assertStringContainsString('id="btn-copy-wa"', $html);
        $this->assertStringContainsString('id="copy-status"', $html);
        $this->assertStringContainsString('quote-success-card', $html);
    }
}
