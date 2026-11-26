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

    public function testCanonicalUrlsComeFromLinkOpenGraphAndJsonLd(): void
    {
        $html = '<html><head>'
            . '<link rel="canonical" href="https://www.booking.com/hotel/br/casa.pt-br.html">'
            . '<meta property="og:url" content="https://www.booking.com/hotel/br/casa.html?aid=1">'
            . '<script type="application/ld+json">' . json_encode([
                '@type' => 'Hotel',
                '@id' => 'https://www.booking.com/hotel/br/casa.html#listing',
                'url' => 'https://www.booking.com/hotel/br/casa.html',
                'image' => ['https://cf.bstatic.com/x.jpg'],
            ]) . '</script></head></html>';

        $urls = OtaListingExtractor::extractCanonicalUrls($html);

        $this->assertContains('https://www.booking.com/hotel/br/casa.pt-br.html', $urls);
        $this->assertContains('https://www.booking.com/hotel/br/casa.html?aid=1', $urls);
        $this->assertContains('https://www.booking.com/hotel/br/casa.html#listing', $urls);
        $this->assertNotContains('https://cf.bstatic.com/x.jpg', $urls);
    }

    public function testWafChallengeShellDetection(): void
    {
        $this->assertTrue(OtaListingExtractor::isWafChallengeShell('<html><head><title></title></head><body><script>reportChallengeError()</script></body></html>'));
        $this->assertTrue(OtaListingExtractor::isWafChallengeShell('<html><script src="/awswaf.js"></script></html>'));
        $this->assertTrue(OtaListingExtractor::isWafChallengeShell('<html><script>captcha-pwa</script></html>'));
        $this->assertFalse(OtaListingExtractor::isWafChallengeShell('<html><head><meta property="og:title" content="Casa"><script>reportChallengeError()</script></head></html>'));
        $this->assertFalse(OtaListingExtractor::isWafChallengeShell('<html><head><script type="application/ld+json">{"@type":"Hotel"}</script></head></html>'));
        $this->assertFalse(OtaListingExtractor::isWafChallengeShell(''));
    }

    public function testJsonLdWithoutUsableListingContentStaysNone(): void
    {
        $html = $this->jsonLd(['@type' => 'Hotel', 'address' => ['streetAddress' => 'Rua X']]);
        $out = OtaListingExtractor::extract($html, 'booking');

        $this->assertSame('none', $out['sanitization_flags']['source']);
        $this->assertTrue($out['sanitization_flags']['partial']);
        $this->assertSame('', $out['title']);
    }

    public function testCanonicalUrlsIgnoreBreadcrumbAndItemListJsonLd(): void
    {
        $html = $this->jsonLd([
            '@graph' => [
                [
                    '@type' => 'VacationRental',
                    '@id' => 'https://www.airbnb.com/rooms/123',
                    'url' => 'https://www.airbnb.com/rooms/123',
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => 'https://www.airbnb.com/rooms/breadcrumb-a']],
                        ['@type' => 'ListItem', 'position' => 2, 'url' => 'https://www.airbnb.com/rooms/breadcrumb-b'],
                    ],
                ],
                [
                    '@type' => 'ItemList',
                    'url' => 'https://www.airbnb.com/rooms/list-a',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'url' => 'https://www.airbnb.com/rooms/list-b'],
                    ],
                ],
            ],
        ]);

        $urls = OtaListingExtractor::extractCanonicalUrls($html);

        $this->assertContains('https://www.airbnb.com/rooms/123', $urls);
        $this->assertNotContains('https://www.airbnb.com/rooms/breadcrumb-a', $urls);
        $this->assertNotContains('https://www.airbnb.com/rooms/breadcrumb-b', $urls);
        $this->assertNotContains('https://www.airbnb.com/rooms/list-a', $urls);
        $this->assertNotContains('https://www.airbnb.com/rooms/list-b', $urls);
    }

    public function testAirbnbJsonLdCombinesWithHydrationForRoomsAmenitiesRulesAndFullGallery(): void
    {
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'VacationRental',
            'name' => 'Vista Livre Pinheiros',
            'description' => 'Apartamento completo com piscina.',
            'image' => [
                'https://a0.muscache.com/im/pictures/cover.jpeg',
                'https://a0.muscache.com/im/pictures/p2.jpeg',
            ],
            'containsPlace' => [
                '@type' => 'Accommodation',
                'occupancy' => ['@type' => 'QuantitativeValue', 'value' => 3],
            ],
            'address' => ['addressLocality' => 'São Paulo'],
        ], JSON_UNESCAPED_UNICODE);

        $hydration = json_encode([
            'niobeClientData' => [[
                'StaysPdpSections:{}',
                [
                    'data' => [
                        'presentation' => [
                            'stayProductDetailPage' => [
                                'sections' => [
                                    'sections' => [[
                                        'sectionId' => 'BOOK_IT_SIDEBAR',
                                        'section' => [
                                            '__typename' => 'BookItSection',
                                            'maxGuestCapacity' => 3,
                                            'tripDetailsLoggingEventData' => [
                                                'clearDatesButton' => ['__typename' => 'BasicListItem', 'title' => 'Limpar datas'],
                                            ],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                        'node' => [
                            '__typename' => 'DemandStayListing',
                            'personCapacity' => 3,
                            'petPolicy' => ['__typename' => 'StayPetPolicy', 'isAllowed' => true],
                            'pdpPresentation' => [
                                'overview' => [
                                    '__typename' => 'StaysPdpOverview',
                                    'title' => 'Espaço inteiro: apartamento em São Paulo, Brasil',
                                    'items' => ['3 hóspedes', '2 quartos', '3 camas', '2 banheiros'],
                                ],
                                'amenities' => [
                                    'seeAllAmenitiesGroups' => [
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Banheiro',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Secador de cabelo', 'icon' => 'SYSTEM_HAIRDRYER'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Água quente', 'icon' => 'SYSTEM_HOT_WATER'],
                                            ],
                                        ],
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Quarto e lavanderia',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Máquina de lavar', 'icon' => 'SYSTEM_WASHER'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Secadora', 'icon' => 'SYSTEM_DRYER'],
                                            ],
                                        ],
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Entretenimento',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Equipamento para exercícios: halteres', 'icon' => 'SYSTEM_GYM'],
                                            ],
                                        ],
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Climatização',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Ar-condicionado', 'icon' => 'SYSTEM_SNOWFLAKE'],
                                            ],
                                        ],
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Internet e escritório',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Wi-Fi', 'icon' => 'SYSTEM_WI_FI'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Espaço de trabalho exclusivo', 'icon' => 'SYSTEM_WORKSPACE'],
                                            ],
                                        ],
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Cozinha e sala de jantar',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Cozinha', 'icon' => 'SYSTEM_COOKING_BASICS'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Itens básicos de cozinha', 'icon' => 'SYSTEM_COOKING_BASICS'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Máquina de lavar louça', 'icon' => 'SYSTEM_DISHWASHER'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Cafeteira', 'icon' => 'SYSTEM_COFFEE_MAKER'],
                                            ],
                                        ],
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Estacionamento e instalações',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Estacionamento gratuito no local', 'icon' => 'SYSTEM_MAPS_CAR_RENTAL'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Piscina', 'icon' => 'SYSTEM_POOL'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Carregador de veículos elétricos', 'icon' => 'SYSTEM_EV_CHARGER'],
                                                ['__typename' => 'AmenityItem', 'available' => true, 'title' => 'Academia', 'icon' => 'SYSTEM_GYM'],
                                            ],
                                        ],
                                        [
                                            '__typename' => 'AmenityItemsGroup',
                                            'title' => 'Não incluso',
                                            'amenities' => [
                                                ['__typename' => 'AmenityItem', 'available' => false, 'title' => 'Detector de fumaça', 'icon' => 'SYSTEM_NO_DETECTOR_SMOKE'],
                                            ],
                                        ],
                                    ],
                                ],
                                'rules' => [
                                    '__typename' => 'StaysPdpRuleDetails',
                                    'title' => 'Regras da Casa',
                                    'groupItems' => [[
                                        '__typename' => 'StaysPdpDetailGroup',
                                        'items' => [
                                            ['__typename' => 'StaysPdpDetailItem', 'type' => 'HOUSE_RULES_PETS', 'icon' => 'SYSTEM_PETS', 'title' => 'Permitido animais de estimação'],
                                            ['__typename' => 'StaysPdpDetailItem', 'type' => 'HOUSE_RULES_SMOKING_ALLOWED', 'icon' => 'SYSTEM_SMOKING_NOT_ALLOWED', 'title' => 'Proibido fumar'],
                                        ],
                                    ]],
                                ],
                                'mediaTour' => [
                                    '__typename' => 'MediaTour',
                                    'name' => 'Tour por fotos',
                                    'stops' => [[
                                        'items' => [
                                            ['image' => ['uri' => 'https://a0.muscache.com/im/pictures/p3.png']],
                                            ['image' => ['uri' => 'https://a0.muscache.com/im/pictures/cover.jpeg']],
                                            ['image' => ['uri' => 'https://a0.muscache.com/im/pictures/p4.jpeg']],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $html = '<html><head>'
            . '<script type="application/ld+json">' . $jsonLd . '</script>'
            . '<script id="data-deferred-state-0" type="application/json">' . $hydration . '</script>'
            . '</head><body></body></html>';

        $out = OtaListingExtractor::extract($html, 'airbnb');

        $this->assertSame('json_ld', $out['sanitization_flags']['source']);
        $this->assertSame('Vista Livre Pinheiros', $out['title']);
        $this->assertSame(3, $out['suggested_guests']);
        $this->assertSame(2, $out['suggested_bedrooms']);
        $this->assertSame(3, $out['suggested_beds']);
        $this->assertSame([
            'https://a0.muscache.com/im/pictures/cover.jpeg',
            'https://a0.muscache.com/im/pictures/p2.jpeg',
            'https://a0.muscache.com/im/pictures/p3.png',
            'https://a0.muscache.com/im/pictures/p4.jpeg',
        ], $out['photos']);
        foreach ([
            'wifi', 'ac', 'washer', 'dryer', 'hair_dryer', 'hot_water', 'coffee',
            'dishwasher', 'workspace', 'kitchen', 'pots', 'utensils', 'pool',
            'parking_free_onsite', 'ev_charger', 'gym', 'exercise',
        ] as $expectedAmenity) {
            $this->assertContains($expectedAmenity, $out['amenities'], "Missing amenity: $expectedAmenity");
        }
        $this->assertNotContains('smoke', $out['amenities']);
        $this->assertTrue($out['suggested_rules']['pets'] ?? false);
        $this->assertFalse($out['suggested_rules']['smoking'] ?? true);
    }

    public function testAirbnbHydrationWithoutJsonLdIgnoresUiButtonTitlesAndExtractsOverview(): void
    {
        $hydration = json_encode([
            'niobeClientData' => [[
                'StaysPdpSections:{}',
                [
                    'data' => [
                        'presentation' => [
                            'stayProductDetailPage' => [
                                'sections' => [
                                    'sections' => [[
                                        'sectionId' => 'BOOK_IT_SIDEBAR',
                                        'section' => [
                                            'tripDetailsLoggingEventData' => [
                                                'clearDatesButton' => ['__typename' => 'BasicListItem', 'title' => 'Limpar datas'],
                                            ],
                                        ],
                                    ]],
                                ],
                            ],
                        ],
                        'node' => [
                            '__typename' => 'DemandStayListing',
                            'personCapacity' => 4,
                            'description' => ['name' => ['localizedString' => 'Casa Solar']],
                            'contextualizedMedia' => [
                                'edges' => [['node' => ['uri' => 'https://a0.muscache.com/im/pictures/hero.jpeg']]],
                            ],
                            'pdpPresentation' => [
                                'overview' => [
                                    '__typename' => 'StaysPdpOverview',
                                    'title' => 'Espaço inteiro: casa',
                                    'items' => ['4 hóspedes', '3 quartos', '4 camas'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $html = '<html><head><script id="data-deferred-state-0" type="application/json">' . $hydration . '</script></head></html>';
        $out = OtaListingExtractor::extract($html, 'airbnb');

        $this->assertSame('hydration', $out['sanitization_flags']['source']);
        $this->assertSame('Casa Solar', $out['title']);
        $this->assertSame(4, $out['suggested_guests']);
        $this->assertSame(3, $out['suggested_bedrooms']);
        $this->assertSame(4, $out['suggested_beds']);
        $this->assertSame(['https://a0.muscache.com/im/pictures/hero.jpeg'], $out['photos']);
    }

    public function testOpenGraphParsesRoomCountsFromAirbnbTitle(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="Apartamento · São Paulo · ★5,0 · 2 quartos · 3 camas · 2 banheiros">'
            . '<meta property="og:description" content="Vista linda.">'
            . '<meta property="og:image" content="https://a0.muscache.com/im/pictures/og.jpeg">'
            . '</head></html>';

        $out = OtaListingExtractor::extract($html, 'airbnb');
        $this->assertSame('opengraph', $out['sanitization_flags']['source']);
        $this->assertSame(2, $out['suggested_bedrooms']);
        $this->assertSame(3, $out['suggested_beds']);
    }

    public function testBookingJsonLdCombinesWithApolloFacilitiesHotelPhotosAndHtmlDom(): void
    {
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Apartment',
            'name' => 'Ap 910 Morumbi',
            'description' => 'Apartamento aconchegante.',
            'image' => 'https://cf.bstatic.com/xdata/images/hotel/max500/590115980.jpg?k=abc&o=&hp=1',
        ], JSON_UNESCAPED_UNICODE);

        $apollo = json_encode([
            'ROOT_QUERY' => [
                'property' => [
                    'facilityGroups' => [[
                        '__typename' => 'FacilityGroup',
                        'facilities' => [
                            ['__typename' => 'BaseFacility', 'name' => 'Wi-Fi gratuito'],
                            ['__typename' => 'BaseFacility', 'name' => 'Estacionamento gratuito'],
                            ['__typename' => 'BaseFacility', 'name' => 'Quartos para não fumantes'],
                            ['__typename' => 'BaseFacility', 'name' => 'Piscina ao ar livre'],
                        ],
                    ]],
                ],
                'similarProperties' => [
                    [
                        '__typename' => 'SimilarProperty',
                        'facilities' => [['__typename' => 'BaseFacility', 'name' => 'Sauna']],
                        'photos' => ['https://cf.bstatic.com/xdata/images/hotel/max1024x768/999999999.jpg?k=sim&o='],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $html = '<html><head>'
            . '<script type="application/ld+json">' . $jsonLd . '</script>'
            . '<script type="application/json" data-capla-store-data="apollo">' . $apollo . '</script>'
            . '<script>booking.env.b_hotel_data = { hotelPhotos: ['
            . "{id: '590115980', large_url: 'https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115980.jpg?k=abc&o=', thumb_url: 'https://cf.bstatic.com/xdata/images/hotel/square60/590115980.jpg?k=abc&o='},"
            . "{id: '590115981', highres_url: 'https://cf.bstatic.com/xdata/images/hotel/max1280x900/590115981.jpg?k=def&o=', large_url: 'https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115981.jpg?k=def&o='},"
            . "{id: '590115982', large_url: 'https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115982.jpg?k=ghi&o='}"
            . '] };</script>'
            . '</head><body>'
            . '<div data-testid="property-most-popular-facilities-wrapper"><ul>'
            . '<li><span>Ar-condicionado</span></li>'
            . '<li><span>Máquina de lavar roupa</span></li>'
            . '</ul></div>'
            . '<div class="bh-photo-grid">'
            . '<a href="https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115983.jpg?k=jkl&o=">foto</a>'
            . '</div>'
            . '</body></html>';

        $out = OtaListingExtractor::extract($html, 'booking');

        $this->assertSame('json_ld', $out['sanitization_flags']['source']);
        foreach (['wifi', 'parking_free_onsite', 'pool', 'ac', 'washer'] as $expected) {
            $this->assertContains($expected, $out['amenities'], "Missing Booking amenity: $expected");
        }
        $this->assertNotContains('smoking', $out['amenities']);
        $this->assertNotContains('sauna', $out['amenities']);

        $this->assertCount(4, $out['photos']);
        $this->assertSame('https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115980.jpg?k=abc&o=', $out['photos'][0]);
        $this->assertContains('https://cf.bstatic.com/xdata/images/hotel/max1280x900/590115981.jpg?k=def&o=', $out['photos']);
        $this->assertContains('https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115982.jpg?k=ghi&o=', $out['photos']);
        $this->assertContains('https://cf.bstatic.com/xdata/images/hotel/max1024x768/590115983.jpg?k=jkl&o=', $out['photos']);
        $this->assertNotContains('https://cf.bstatic.com/xdata/images/hotel/max1024x768/999999999.jpg?k=sim&o=', $out['photos']);
    }

    public function testBookingPrefersLocalizedDomDescriptionOverEnglishJsonLdSummary(): void
    {
        $jsonLd = json_encode([
            '@type' => 'Apartment',
            'name' => 'Ap 910 Morumbi',
            'description' => 'This apartment offers accommodations with a terrace and free Wifi.',
            'image' => 'https://cf.bstatic.com/xdata/images/hotel/max500/590115980.jpg?k=abc&o=',
        ], JSON_UNESCAPED_UNICODE);

        $htmlLegacy = '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body>'
            . '<div id="property_description_content"><p>Apartamento amplo no Morumbi com varanda e piscina.</p></div>'
            . '</body></html>';
        $outLegacy = OtaListingExtractor::extract($htmlLegacy, 'booking');
        $this->assertSame('Apartamento amplo no Morumbi com varanda e piscina.', $outLegacy['description']);

        $htmlModern = '<html><head><script type="application/ld+json">' . $jsonLd . '</script></head><body>'
            . '<p data-testid="property-description">Apartamento moderno com dois quartos e cozinha equipada.</p>'
            . '</body></html>';
        $outModern = OtaListingExtractor::extract($htmlModern, 'booking');
        $this->assertSame('Apartamento moderno com dois quartos e cozinha equipada.', $outModern['description']);
    }

    public function testBookingHotelPhotosHandlesNestedObjectsQuotedKeysAndBracesInAlt(): void
    {
        $html = '<html><head><script>booking.env.b_hotel_data = { "hotelPhotos": ['
            . '{ "id": "111", "grid": { "photo_width": 4080, "photo_height": 3072 }, "associated_rooms": [{"room_id": 7}], "alt": "Vista {piscina} [linda]", "highres_url": "https://cf.bstatic.com/xdata/images/hotel/max1280x900/111.jpg?k=a&o=", "large_url": "https://cf.bstatic.com/xdata/images/hotel/max1024x768/111.jpg?k=a&o=", "thumb_url": "https://cf.bstatic.com/xdata/images/hotel/square60/111.jpg?k=a&o=" },'
            . "{ id: '222', grid: { photo_width: 4000, photo_height: 3000 }, alt: 'Quarto \\'duplo\\' com {chaves} e [colchetes]', large_url: 'https://cf.bstatic.com/xdata/images/hotel/max1024x768/222.jpg?k=b&o=' },"
            . '{ "id": "333", "highResUrl": "https://cf.bstatic.com/xdata/images/hotel/max1280x900/333.jpg?k=c&o=", "url": "https://cf.bstatic.com/xdata/images/hotel/max1024x768/333.jpg?k=c&o=", },'
            . '] };</script></head><body></body></html>';

        $out = OtaListingExtractor::extract($html, 'booking');

        $this->assertCount(3, $out['photos']);
        $this->assertContains('https://cf.bstatic.com/xdata/images/hotel/max1280x900/111.jpg?k=a&o=', $out['photos']);
        $this->assertContains('https://cf.bstatic.com/xdata/images/hotel/max1024x768/222.jpg?k=b&o=', $out['photos']);
        $this->assertContains('https://cf.bstatic.com/xdata/images/hotel/max1280x900/333.jpg?k=c&o=', $out['photos']);
        $this->assertNotContains('https://cf.bstatic.com/xdata/images/hotel/max1024x768/111.jpg?k=a&o=', $out['photos']);
        $this->assertNotContains('https://cf.bstatic.com/xdata/images/hotel/square60/111.jpg?k=a&o=', $out['photos']);
        $this->assertNotContains('https://cf.bstatic.com/xdata/images/hotel/max1024x768/333.jpg?k=c&o=', $out['photos']);
    }

    public function testBookingHotelPhotosHandlesEmptyAndTruncatedArrays(): void
    {
        $empty = '<html><head><script>booking.env.b_hotel_data = { hotelPhotos: [] };</script></head><body></body></html>';
        $outEmpty = OtaListingExtractor::extract($empty, 'booking');
        $this->assertSame([], $outEmpty['photos']);

        $truncated = '<html><head><script>booking.env.b_hotel_data = { hotelPhotos: ['
            . "{ id: '444', large_url: 'https://cf.bstatic.com/xdata/images/hotel/max1024x768/444.jpg?k=d&o=' },"
            . "{ id: '445', grid: { photo_width: 4000";
        $outTruncated = OtaListingExtractor::extract($truncated, 'booking');
        $this->assertIsArray($outTruncated['photos']);
    }
}
