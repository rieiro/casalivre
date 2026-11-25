<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/OtaListingExtractor.php';

final class OtaListingExtractorTest extends TestCase
{
    private function jsonLd(array $data): string
    {
        return '<html><head><script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_UNICODE)
            . '</script></head><body></body></html>';
    }

    public function testJsonLdVacationRentalExtractsAndScrubsContent(): void
    {
        $html = $this->jsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'VacationRental',
            'name' => 'Casa Superhost Airbnb Vista Mar',
            'description' => 'Linda casa do Superhost. Reserve em https://www.airbnb.com/rooms/123 e ganhe Genius.',
            'image' => ['https://a0.muscache.com/im/pictures/1.jpg?im_w=720'],
            'amenityFeature' => [
                ['@type' => 'LocationFeatureSpecification', 'name' => 'Wifi'],
                ['@type' => 'LocationFeatureSpecification', 'name' => 'Piscina'],
                ['@type' => 'LocationFeatureSpecification', 'name' => 'Heliporto particular'],
            ],
            'occupancy' => ['@type' => 'QuantitativeValue', 'maxValue' => 6],
            'numberOfRooms' => 3,
            'numberOfBeds' => 4,
            'checkinTime' => '14:00',
            'checkoutTime' => '10:00',
            'priceRange' => 'R$ 350',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Rua Secreta 42',
                'addressLocality' => 'Florianópolis',
                'addressRegion' => 'SC',
                'postalCode' => '88000-000',
            ],
            'review' => [['@type' => 'Review', 'reviewBody' => 'Melhor hospedagem da vida']],
        ]);

        $out = OtaListingExtractor::extract($html, 'airbnb');

        $this->assertSame('json_ld', $out['sanitization_flags']['source']);
        $this->assertFalse($out['sanitization_flags']['partial']);
        $this->assertStringNotContainsString('Airbnb', (string) $out['title']);
        $this->assertStringNotContainsString('Superhost', (string) $out['title']);
        $this->assertStringNotContainsString('airbnb.com', (string) $out['description']);
        $this->assertStringNotContainsString('Genius', (string) $out['description']);
        $this->assertStringNotContainsString('Melhor hospedagem', (string) $out['description']);
        $this->assertGreaterThanOrEqual(2, (int) $out['sanitization_flags']['platform_names']);
        $this->assertGreaterThanOrEqual(1, (int) $out['sanitization_flags']['external_urls']);
        $this->assertContains('https://a0.muscache.com/im/pictures/1.jpg?im_w=720', $out['photos']);
        $this->assertContains('wifi', $out['amenities']);
        $this->assertContains('pool', $out['amenities']);
        $this->assertContains('Heliporto particular', $out['unmapped_amenities']);
        $this->assertSame(6, $out['suggested_guests']);
        $this->assertSame(3, $out['suggested_bedrooms']);
        $this->assertSame(4, $out['suggested_beds']);
        $this->assertSame('14:00', $out['suggested_checkin']);
        $this->assertSame(350, $out['suggested_nightly_price']);
        $this->assertTrue($out['unconfirmed']['address']);
        $this->assertTrue($out['unconfirmed']['nightly_price']);
        $this->assertSame('Rua Secreta 42', $out['suggested_address']['street']);
    }

    public function testHydrationJsonBlobIsUsedBeforeOpenGraph(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="OG Title">'
            . '<script id="data-deferred-state" type="application/json">'
            . json_encode([
                'listing' => [
                    'title' => 'Loft na praia',
                    'description' => 'Vista para o mar.',
                    'images' => ['https://a0.muscache.com/im/pictures/2.jpg'],
                    'amenities' => ['Wi-Fi', 'Ar-condicionado', 'Jacuzzi presidencial'],
                ],
            ])
            . '</script></head><body></body></html>';

        $out = OtaListingExtractor::extract($html, 'airbnb');
        $this->assertSame('hydration', $out['sanitization_flags']['source']);
        $this->assertSame('Loft na praia', $out['title']);
        $this->assertSame('Vista para o mar.', $out['description']);
        $this->assertContains('wifi', $out['amenities']);
        $this->assertContains('ac', $out['amenities']);
        $this->assertContains('Jacuzzi presidencial', $out['unmapped_amenities']);
    }

    public function testOpenGraphFallbackWhenNoStructuredMarkup(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="Casa Booking.com">'
            . '<meta property="og:description" content="Perto da praia. www.exemplo.com">'
            . '<meta property="og:image" content="https://cf.bstatic.com/xdata/images/hotel/1.jpg">'
            . '<meta property="og:image" content="https://cf.bstatic.com/xdata/images/hotel/2.jpg">'
            . '</head><body>Sem JSON.</body></html>';

        $out = OtaListingExtractor::extract($html, 'booking');
        $this->assertSame('opengraph', $out['sanitization_flags']['source']);
        $this->assertStringNotContainsString('Booking.com', (string) $out['title']);
        $this->assertStringNotContainsString('exemplo.com', (string) $out['description']);
        $this->assertCount(2, $out['photos']);
    }

    public function testMinimalMarkupFallsBackToPartialDraft(): void
    {
        $out = OtaListingExtractor::extract('<html><body>Bloqueado</body></html>', 'vrbo');
        $this->assertSame('none', $out['sanitization_flags']['source']);
        $this->assertTrue($out['sanitization_flags']['partial']);
        $this->assertSame('', $out['title']);
        $this->assertSame([], $out['photos']);
        $this->assertTrue($out['unconfirmed']['house_rules']);
    }

    public function testTitleAndDescriptionAreClippedToFormLimits(): void
    {
        $html = $this->jsonLd([
            '@type' => 'LodgingBusiness',
            'name' => str_repeat('A', 80),
            'description' => str_repeat('B', 900),
        ]);
        $out = OtaListingExtractor::extract($html, 'booking');
        $this->assertSame(25, mb_strlen((string) $out['title']));
        $this->assertSame(500, mb_strlen((string) $out['description']));
    }
}
