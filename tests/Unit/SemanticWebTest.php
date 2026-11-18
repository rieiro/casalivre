<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/PublicListing.php';
require_once dirname(__DIR__, 2) . '/src/MarkdownListing.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';

final class SemanticWebTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
    }

    public function testPublicListingNeverLeaksSensitiveFields(): void
    {
        $house = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($house);

        $listing = public_listing($house);

        $this->assertArrayNotHasKey('address', $listing);
        $this->assertArrayNotHasKey('street', $listing);
        $this->assertArrayNotHasKey('address_number', $listing);
        $this->assertArrayNotHasKey('address_complement', $listing);
        $this->assertArrayNotHasKey('cep', $listing);
        $this->assertArrayNotHasKey('lat', $listing);
        $this->assertArrayNotHasKey('lng', $listing);
        $this->assertArrayNotHasKey('pix_key', $listing);
        $this->assertArrayNotHasKey('ical_hash', $listing);
        $this->assertArrayNotHasKey('external_ical_url', $listing);
        $this->assertArrayNotHasKey('user_id', $listing);

        $this->assertArrayHasKey('id', $listing);
        $this->assertArrayHasKey('title', $listing);
        $this->assertArrayHasKey('city', $listing);
        $this->assertArrayHasKey('nightly_price', $listing);
    }

    public function testJsonLdVacationRentalOutput(): void
    {
        $house = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $listing = public_listing($house);

        $jsonStr = jsonld_vacation_rental($listing, 'casalivre.com.br');
        $data = json_decode($jsonStr, true);

        $this->assertIsArray($data);
        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('VacationRental', $data['@type']);
        $this->assertSame($listing['title'], $data['name']);
        $this->assertSame('BRL', $data['offers']['priceCurrency']);
        $this->assertSame($listing['nightly_price'], $data['offers']['price']);

        $this->assertArrayNotHasKey('streetAddress', $data['address']);
        $this->assertArrayNotHasKey('postalCode', $data['address']);
    }

    public function testMarkdownRenderingProducesCleanText(): void
    {
        $house = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $listing = public_listing($house);

        $md = render_property_markdown($listing, 'casalivre.com.br');

        $this->assertStringContainsString('# ' . $listing['title'], $md);
        $this->assertStringContainsString('city: ' . $listing['city'], $md);
        $this->assertStringContainsString('nightly_brl: ' . $listing['nightly_price'], $md);
        $this->assertStringNotContainsString('pix_key', $md);
        $this->assertStringNotContainsString('address', $md);
    }

    public function testPrefersMarkdownNegotiation(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/markdown;q=0.9, text/html;q=0.8';
        $this->assertTrue(prefers_markdown());

        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9';
        $this->assertFalse(prefers_markdown());

        unset($_SERVER['HTTP_ACCEPT']);
        $this->assertFalse(prefers_markdown());
    }

    public function testPublicListingSeparatesHouseRulesFromAmenitiesAndClarifiesQuietHours(): void
    {
        $house = [
            'id' => 9999,
            'user_id' => 1,
            'title' => 'Casa Teste Regras',
            'city' => 'Rio de Janeiro',
            'uf' => 'RJ',
            'bedrooms' => 2,
            'beds' => 2,
            'max_guests' => 4,
            'nightly_price' => 30000,
            'cleaning_fee' => 5000,
            'min_nights' => 2,
            'checkin_time' => '14:00',
            'checkout_time' => '11:00',
            'amenities' => json_encode([
                'wifi' => 1,
                'ac' => 1,
                'noise' => 1,
                'noise_from' => '22:00',
                'noise_until' => '07:00',
                'pets' => 1,
                'wet_elevator' => 1,
                'smoking' => 0,
                'parties' => 0,
            ]),
            'public_hash' => '1234567890abcdef',
            'status' => 'active',
            'rating_count' => 0,
            'rating_sum' => 0,
        ];

        $listing = public_listing($house);

        // Amenities must exclude house rule keys
        $this->assertContains('wifi', $listing['amenities']);
        $this->assertContains('ac', $listing['amenities']);
        $this->assertNotContains('noise', $listing['amenities']);
        $this->assertNotContains('pets', $listing['amenities']);
        $this->assertNotContains('wet_elevator', $listing['amenities']);
        $this->assertNotContains('smoking', $listing['amenities']);
        $this->assertNotContains('parties', $listing['amenities']);

        // House rules must be explicitly structured
        $this->assertArrayHasKey('house_rules', $listing);
        $rules = $listing['house_rules'];
        $this->assertTrue($rules['quiet_hours']['enabled']);
        $this->assertSame('22:00', $rules['quiet_hours']['from']);
        $this->assertSame('07:00', $rules['quiet_hours']['until']);
        $this->assertStringContainsString('proibido', strtolower($rules['quiet_hours']['description']));
        $this->assertTrue($rules['pets_allowed']);
        $this->assertFalse($rules['smoking_allowed']);
        $this->assertTrue($rules['wet_clothes_in_elevator_prohibited']);
        $this->assertFalse($rules['parties_allowed']);

        // Top level quiet hours aliases
        $this->assertSame('22:00', $listing['quiet_hours_from']);
        $this->assertSame('07:00', $listing['quiet_hours_until']);
    }

    public function testMarkdownRenderingSeparatesHouseRulesAndClarifiesQuietHours(): void
    {
        $house = [
            'id' => 9999,
            'user_id' => 1,
            'title' => 'Casa Teste Markdown Regras',
            'city' => 'Rio de Janeiro',
            'uf' => 'RJ',
            'bedrooms' => 2,
            'beds' => 2,
            'max_guests' => 4,
            'nightly_price' => 30000,
            'cleaning_fee' => 5000,
            'min_nights' => 2,
            'checkin_time' => '14:00',
            'checkout_time' => '11:00',
            'summary' => 'Casa aconchegante perto da praia.',
            'description' => 'Descrição completa do imóvel.',
            'amenities' => json_encode([
                'wifi' => 1,
                'ac' => 1,
                'noise' => 1,
                'noise_from' => '22:00',
                'noise_until' => '07:00',
                'pets' => 1,
                'wet_elevator' => 1,
            ]),
            'public_hash' => '1234567890abcdef',
            'status' => 'active',
            'rating_count' => 0,
            'rating_sum' => 0,
        ];

        $listing = public_listing($house);
        $md = render_property_markdown($listing, 'casalivre.com.br');

        // Amenities string must NOT contain noise or pets or wet_elevator
        $this->assertMatchesRegularExpression('/amenities:\s*.*wifi/i', $md);
        $this->assertDoesNotMatchRegularExpression('/amenities:\s*.*noise/i', $md);
        $this->assertDoesNotMatchRegularExpression('/amenities:\s*.*pets/i', $md);

        // Dedicated house rules section
        $this->assertStringContainsString('## Regras da Casa', $md);
        $this->assertStringContainsString('22:00', $md);
        $this->assertStringContainsString('07:00', $md);
        // Explicitly clarifies that noise is forbidden during quiet hours
        $this->assertMatchesRegularExpression('/(silêncio|quiet hours).*?(proibido|não é permitido|no noise)/i', $md);
        $this->assertStringContainsString('animais', strtolower($md));
    }
}
