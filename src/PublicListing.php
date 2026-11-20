<?php

declare(strict_types=1);

require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/Amenities.php';
require_once __DIR__ . '/HouseRepository.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/CepRepository.php';
require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/GooglePlacesService.php';
require_once __DIR__ . '/OtaVerificationService.php';

/**
 * Shared public representation of a property (ADR/Architecture §3 & §4).
 * Strips PII: no address, street, cep, lat, lng, pix_key, ical_hash, or users.*
 *
 * @param array<string, mixed> $house
 * @return array<string, mixed>
 */
function public_listing(array $house, string $checkin = '', string $checkout = ''): array
{
    $id = (int) $house['id'];
    $nights = nights_between($checkin, $checkout);
    $quote = $nights ? stay_quote($house, $nights) : null;
    $photos = api_public_photos(PhotoRepository::listReady($id));
    $selected = Amenities::selected($house);
    $amenities = [];
    foreach (Amenities::amenityKeys() as $key) {
        if (!empty($selected[$key])) {
            $amenities[] = $key;
        }
    }
    $houseRules = Amenities::rules($house);
    $score = HouseRepository::score($house);
    $ratingCount = (int) ($house['rating_count'] ?? 0);
    $noiseFrom = (string) ($selected['noise_from'] ?? '22:00');
    $noiseUntil = (string) ($selected['noise_until'] ?? '07:00');

    return [
        'id' => property_ref($house),
        'title' => (string) ($house['title'] ?? ''),
        'description' => (string) ($house['description'] ?? ''),
        'summary' => (string) ($house['summary'] ?? ''),
        'city' => (string) ($house['city'] ?? ''),
        'uf' => (string) ($house['uf'] ?? ''),
        'neighborhood' => house_neighborhood($house),
        'rating' => $score !== null ? round((float) $score, 1) : null,
        'rating_count' => $ratingCount,
        // Reputação efêmera do Google (null sem chave, cache expirado ou falha do upstream).
        'google_reputation' => GooglePlacesService::getReputation(
            isset($house['google_place_id']) ? (string) $house['google_place_id'] : null,
            current_locale()
        ),
        // Selo "Anúncio Verificado em Plataformas Parceiras" (nunca expõe tokens do iCal).
        'ota_verifications' => OtaVerificationService::listVerifiedForProperty($id),
        'quote' => $quote,
        'photos' => $photos,
        'amenities' => $amenities,
        'house_rules' => $houseRules,
        'bedrooms' => (int) ($house['bedrooms'] ?? 0),
        'beds' => (int) ($house['beds'] ?? 0),
        'max_guests' => (int) ($house['max_guests'] ?? 0),
        'nightly_price' => (int) ($house['nightly_price'] ?? 0),
        'min_nights' => (int) ($house['min_nights'] ?? 1),
        'checkin_time' => (string) ($house['checkin_time'] ?? '14:00'),
        'checkout_time' => (string) ($house['checkout_time'] ?? '11:00'),
        'cleaning_fee' => (int) ($house['cleaning_fee'] ?? 0),
        'noise_from' => $noiseFrom,
        'noise_until' => $noiseUntil,
        'quiet_hours_from' => $noiseFrom,
        'quiet_hours_until' => $noiseUntil,
        'blocked_days' => HouseRepository::calendarDaysUntil($id, max_calendar_day($house)),
    ];
}

/**
 * Builds Schema.org/VacationRental JSON-LD payload (Architecture §3.1).
 *
 * @param array<string, mixed> $listing Result of public_listing()
 */
function jsonld_vacation_rental(array $listing, string $host = ''): string
{
    $host = $host !== '' ? $host : ($_SERVER['HTTP_HOST'] ?? 'casalivre.com.br');
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'https';
    $url = $scheme . '://' . $host . '/imoveis/' . $listing['id'];

    $images = [];
    if (!empty($listing['photos']) && is_array($listing['photos'])) {
        foreach ($listing['photos'] as $p) {
            if (!empty($p['path'])) {
                $images[] = $scheme . '://' . $host . $p['path'];
            }
        }
    }

    $amenities = [];
    if (!empty($listing['amenities']) && is_array($listing['amenities'])) {
        foreach ($listing['amenities'] as $am) {
            $amenities[] = [
                '@type' => 'LocationFeatureSpecification',
                'name' => (string) $am,
                'value' => true,
            ];
        }
    }

    $doc = [
        '@context' => 'https://schema.org',
        '@type' => 'VacationRental',
        'name' => (string) ($listing['title'] ?? ''),
        'description' => (string) ($listing['description'] ?? $listing['summary'] ?? ''),
        'url' => $url,
        'identifier' => (string) ($listing['id'] ?? ''),
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => (string) ($listing['city'] ?? ''),
            'addressRegion' => (string) ($listing['uf'] ?? ''),
            'addressCountry' => 'BR',
        ],
        'containsPlace' => [
            '@type' => 'Accommodation',
            'occupancy' => [
                '@type' => 'QuantitativeValue',
                'value' => 0,
                'maxValue' => (int) ($listing['max_guests'] ?? 0),
            ],
            'numberOfBedrooms' => (int) ($listing['bedrooms'] ?? 0),
            'numberOfBeds' => (int) ($listing['beds'] ?? 0),
        ],
        'checkinTime' => (string) ($listing['checkin_time'] ?? '14:00'),
        'checkoutTime' => (string) ($listing['checkout_time'] ?? '11:00'),
        'image' => $images,
        'amenityFeature' => $amenities,
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'BRL',
            'price' => (int) ($listing['nightly_price'] ?? 0),
            'priceSpecification' => [
                [
                    '@type' => 'UnitPriceSpecification',
                    'price' => (int) ($listing['nightly_price'] ?? 0),
                    'priceCurrency' => 'BRL',
                    'unitText' => 'NIGHT',
                ],
                [
                    '@type' => 'UnitPriceSpecification',
                    'price' => (int) ($listing['cleaning_fee'] ?? 0),
                    'priceCurrency' => 'BRL',
                    'unitText' => 'CLEANING',
                ],
            ],
        ],
    ];

    if (!empty($listing['neighborhood'])) {
        $doc['containedInPlace'] = [
            '@type' => 'Place',
            'name' => (string) $listing['neighborhood'],
        ];
    }

    if (!empty($listing['rating_count']) && $listing['rating_count'] > 0 && $listing['rating'] !== null) {
        $doc['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => (float) $listing['rating'],
            'bestRating' => 10,
            'reviewCount' => (int) $listing['rating_count'],
        ];
    }

    return (string) json_encode(
        $doc,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}
