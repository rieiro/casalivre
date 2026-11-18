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
}
