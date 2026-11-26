<?php

declare(strict_types=1);

require_once __DIR__ . '/Amenities.php';

/**
 * Layered extractor for a public OTA listing page (no network here; the caller fetches the HTML).
 *
 * Priority: JSON-LD (VacationRental/LodgingBusiness/Accommodation) > hydration JSON blobs > OpenGraph.
 * Only descriptive data is returned. Reviews, host badges and travel guides are never copied.
 * The exact address, prices and house rules are suggestions only (never auto-confirmed).
 */
final class OtaListingExtractor
{
    /** @var array<string, list<string>> normalized amenity phrase => CasaLivre keys */
    private const AMENITY_MAP = [
        'wifi' => ['wifi'], 'wi fi' => ['wifi'], 'internet' => ['wifi'], 'wireless internet' => ['wifi'],
        'ethernet' => ['ethernet'], 'workspace' => ['workspace'], 'dedicated workspace' => ['workspace'],
        'air conditioning' => ['ac'], 'airconditioning' => ['ac'], 'ar condicionado' => ['ac'],
        'heating' => ['heating'], 'aquecimento' => ['heating'], 'fireplace' => ['fireplace'], 'lareira' => ['fireplace'],
        'ceiling fan' => ['ceiling_fan'], 'portable fan' => ['portable_fan'],
        'kitchen' => ['kitchen'], 'cozinha' => ['kitchen'], 'stove' => ['stove'], 'oven' => ['oven'],
        'freezer' => ['freezer'], 'mini fridge' => ['minifridge'], 'minibar' => ['minifridge'],
        'dishwasher' => ['dishwasher'], 'coffee maker' => ['coffee'], 'kettle' => ['kettle'],
        'toaster' => ['toaster'], 'blender' => ['blender'], 'microwave' => ['microwave'], 'air fryer' => ['airfryer'],
        'dishes and silverware' => ['dishes', 'cutlery'], 'cooking basics' => ['pots', 'utensils'],
        'wine glasses' => ['wine_glasses'], 'washer' => ['washer'], 'washing machine' => ['washer'],
        'dryer' => ['dryer'], 'clothesline' => ['clothesline'], 'iron' => ['iron'], 'bed linen' => ['linen'],
        'linens' => ['linen'], 'towels' => ['towels'], 'cleaning products' => ['cleaning_products'],
        'hangers' => ['hangers'], 'extra pillows and blankets' => ['extra_bedding'], 'blackout curtains' => ['blackout'],
        'tv' => ['tv'], 'television' => ['tv'], 'sound system' => ['sound_system'], 'game console' => ['game_console'],
        'books' => ['books'], 'exercise equipment' => ['exercise'], 'bikes' => ['bikes'], 'bicycle' => ['bikes'],
        'pool table' => ['pool_table'], 'ping pong table' => ['ping_pong'],
        'garage' => ['garage'], 'free parking on premises' => ['parking_free_onsite'],
        'free parking' => ['parking_free_onsite'], 'paid parking on premises' => ['parking_paid_onsite'],
        'free parking off premises' => ['parking_free_offsite'], 'paid parking off premises' => ['parking_paid_offsite'],
        'ev charger' => ['ev_charger'], 'elevator' => ['elevator'], 'housekeeping' => ['housekeeping'],
        'pool' => ['pool'], 'piscina' => ['pool'], 'bbq grill' => ['bbq', 'bbq_tools'], 'barbecue' => ['bbq'],
        'churrasqueira' => ['bbq'], 'outdoor kitchen' => ['outdoor_kitchen'], 'outdoor dining area' => ['outdoor_dining'],
        'outdoor furniture' => ['outdoor_furniture'], 'sun loungers' => ['lounger'], 'hammock' => ['hammock'],
        'outdoor fireplace' => ['outdoor_fireplace'], 'beach essentials' => ['beach_items'], 'balcony' => ['balcony'],
        'backyard' => ['yard'], 'garden' => ['garden'], 'sea view' => ['sea_view'], 'outdoor shower' => ['outdoor_shower'],
        'lake access' => ['lake_access'], 'beach access' => ['beach_access'], 'private entrance' => ['private_entrance'],
        'laundromat nearby' => ['nearby_laundry'], 'gated community' => ['gated_community'], 'doorman' => ['doorman'],
        'solar panels' => ['solar_panels'], 'hot water' => ['hot_water'], 'bathtub' => ['bathtub'], 'hot tub' => ['hot_tub'],
        'sauna' => ['sauna'], 'crib' => ['crib'], 'high chair' => ['high_chair'], 'changing table' => ['changing_table'],
        'baby bath' => ['baby_bath'], 'pack n play' => ['playpen'], 'baby gate' => ['baby_gate'],
        'smoke alarm' => ['smoke'], 'carbon monoxide alarm' => ['co_detector'], 'fire extinguisher' => ['extinguisher'],
        'first aid kit' => ['first_aid'], 'safe' => ['safe'],
        'pets allowed' => ['pets'], 'smoking allowed' => ['smoking'],
    ];

    /** Platform names/badges never copied into the host's content. */
    private const PLATFORM_PATTERNS = [
        '/\bAirbnb\b/iu', '/\bBooking\.com\b/iu', '/\bBooking\b/iu', '/\bVrbo\b/iu',
        '/\bSuperhost\b/iu', '/\bGenius\b/iu', '/\bPremier Host\b/iu',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function extract(string $html, string $platform): array
    {
        $platform = strtolower(trim($platform));
        $flags = ['platform_names' => 0, 'external_urls' => 0, 'reviews_dropped' => false, 'source' => 'none', 'partial' => true];
        $payload = self::emptyPayload();

        $jsonLd = self::jsonLdNodes($html);
        if ($jsonLd !== []) {
            $method = self::extractFromJsonLd($jsonLd);
            if ($method !== null) {
                $payload = $method;
                $flags['source'] = 'json_ld';
                $flags['partial'] = false;
            }
        }
        if ($flags['source'] === 'none') {
            $hydration = self::hydrationNodes($html);
            if ($hydration !== []) {
                $payload = self::extractFromHydration($hydration);
                $flags['source'] = 'hydration';
                $flags['partial'] = false;
            }
        }
        if ($flags['source'] === 'none') {
            $og = self::openGraph($html);
            if ($og !== []) {
                $payload = self::extractFromOpenGraph($og);
                $flags['source'] = 'opengraph';
                $flags['partial'] = false;
            }
        }

        $title = self::sanitizeText((string) $payload['title'], $flags);
        $description = self::sanitizeText((string) $payload['description'], $flags);
        $payload['title'] = self::clip($title, 25);
        $payload['description'] = self::clip($description, 500);
        $payload['summary'] = self::clip($description, 160);

        $photos = [];
        foreach ((array) $payload['photos'] as $url) {
            $url = is_string($url) ? trim($url) : '';
            if ($url !== '' && preg_match('#\Ahttps://#i', $url) === 1) {
                $photos[$url] = true;
            }
        }
        $payload['photos'] = array_keys($photos);

        $mapped = [];
        $unmapped = [];
        foreach ((array) $payload['raw_amenities'] as $raw) {
            $raw = self::sanitizeText((string) $raw, $flags);
            if ($raw === '') {
                continue;
            }
            $keys = self::mapAmenity($raw);
            if ($keys === []) {
                $unmapped[$raw] = true;
            } else {
                foreach ($keys as $key) {
                    $mapped[$key] = true;
                }
            }
        }
        $payload['amenities'] = array_keys($mapped);
        $payload['unmapped_amenities'] = array_slice(array_keys($unmapped), 0, 60);
        unset($payload['raw_amenities']);

        if (!self::hasUsableContent($payload)) {
            // No listing markup at all (static shell, WAF page or empty body): never claim a partial extraction.
            $flags['source'] = 'none';
            $flags['partial'] = true;
        }

        $payload['unconfirmed'] = [
            'address' => true,
            'nightly_price' => $payload['suggested_nightly_price'] !== null,
            'cleaning_fee' => $payload['suggested_cleaning_fee'] !== null,
            'house_rules' => true,
        ];
        $payload['sanitization_flags'] = $flags;
        return $payload;
    }

    /**
     * Known anti-bot/WAF challenge shell: no listing markup (JSON-LD / og:title) plus a challenge marker.
     * Shared by the HTTP fetch (200 shells) and the host-side raw HTML paste validation.
     */
    public static function isWafChallengeShell(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }
        if (preg_match('#application/ld\+json#i', $html) === 1) {
            return false;
        }
        if (preg_match('#<meta\b[^>]*property\s*=\s*["\']og:title["\']#i', $html) === 1) {
            return false;
        }
        foreach (['reportChallengeError', 'awswaf', 'captcha-pwa', 'wildcard-challenge-handler', 'x-amzn-waf-action', 'challenge-platform'] as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }
        if (preg_match('#\bAWS\s*WAF\b#i', $html) === 1) {
            return true;
        }
        // Booking.com's 202 shell: an empty <title> and challenge/captcha wording only.
        if (preg_match('#<title>\s*</title>#i', $html) === 1
            && preg_match('#\b(?:challenge|captcha|verifique|verify)\b#i', strip_tags($html)) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Candidate canonical listing URLs for anti-spoofing: <link rel="canonical">, og:url and JSON-LD url/@id.
     *
     * @return list<string>
     */
    public static function extractCanonicalUrls(string $html): array
    {
        $urls = [];
        if (preg_match_all('#<link\b[^>]*>#is', $html, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                if (preg_match('#\brel\s*=\s*["\']canonical["\']#i', $tag) !== 1) {
                    continue;
                }
                if (preg_match('#\bhref\s*=\s*["\']([^"\']+)["\']#i', $tag, $m) === 1) {
                    $urls[] = $m[1];
                }
            }
        }
        if (preg_match_all('#<meta\b[^>]*>#is', $html, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                if (preg_match('#\bproperty\s*=\s*["\']og:url["\']#i', $tag) !== 1) {
                    continue;
                }
                if (preg_match('#\bcontent\s*=\s*["\']([^"\']+)["\']#i', $tag, $m) === 1) {
                    $urls[] = $m[1];
                }
            }
        }
        foreach (self::jsonLdNodes($html) as $node) {
            foreach (self::flattenNodes($node) as $candidate) {
                foreach (['url', '@id'] as $key) {
                    if (!isset($candidate[$key])) {
                        continue;
                    }
                    $value = $candidate[$key];
                    if (is_string($value)) {
                        $urls[] = $value;
                    } elseif (is_array($value)) {
                        $first = reset($value);
                        if (is_string($first)) {
                            $urls[] = $first;
                        }
                    }
                }
            }
        }

        $out = [];
        foreach ($urls as $raw) {
            $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (str_starts_with($raw, '//')) {
                $raw = 'https:' . $raw;
            }
            if (preg_match('#\Ahttps?://#i', $raw) !== 1) {
                continue;
            }
            $out[$raw] = true;
        }
        return array_keys($out);
    }

    /** @param array<string, mixed> $payload */
    private static function hasUsableContent(array $payload): bool
    {
        return trim((string) $payload['title']) !== ''
            || trim((string) $payload['description']) !== ''
            || (array) $payload['photos'] !== [];
    }

    /** @return array<string, mixed> */
    private static function emptyPayload(): array
    {
        return [
            'title' => '',
            'description' => '',
            'summary' => '',
            'photos' => [],
            'raw_amenities' => [],
            'amenities' => [],
            'unmapped_amenities' => [],
            'suggested_address' => null,
            'suggested_nightly_price' => null,
            'suggested_cleaning_fee' => null,
            'suggested_guests' => null,
            'suggested_bedrooms' => null,
            'suggested_beds' => null,
            'suggested_checkin' => null,
            'suggested_checkout' => null,
            'suggested_rules' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function jsonLdNodes(string $html): array
    {
        $nodes = [];
        if (preg_match_all('#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m) !== false) {
            foreach ($m[1] as $raw) {
                $decoded = json_decode(trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
                if (is_array($decoded)) {
                    $nodes[] = $decoded;
                }
            }
        }
        return $nodes;
    }

    /** @param list<array<string, mixed>> $nodes @return array<string, mixed>|null */
    private static function extractFromJsonLd(array $nodes): ?array
    {
        $target = null;
        foreach ($nodes as $node) {
            foreach (self::flattenNodes($node) as $candidate) {
                $types = self::types($candidate);
                if (array_intersect($types, ['vacationrental', 'lodgingbusiness', 'accommodation', 'house', 'apartment', 'hotel', 'rental']) !== []) {
                    $target = $candidate;
                    break 2;
                }
            }
        }
        if ($target === null) {
            return null;
        }
        $payload = self::emptyPayload();
        $payload['title'] = self::stringValue($target['name'] ?? $target['headline'] ?? '');
        $payload['description'] = self::stringValue($target['description'] ?? '');
        $payload['photos'] = self::imageList($target['image'] ?? $target['photo'] ?? []);
        $payload['raw_amenities'] = self::amenityList($target['amenityFeature'] ?? $target['amenities'] ?? []);
        $payload['suggested_guests'] = self::intValue($target['occupancy']['maxValue'] ?? $target['guestCapacity'] ?? null);
        $payload['suggested_bedrooms'] = self::intValue($target['numberOfRooms'] ?? $target['numberOfBedrooms'] ?? null);
        $payload['suggested_beds'] = self::intValue($target['numberOfBeds'] ?? null);
        $payload['suggested_checkin'] = self::clockValue($target['checkinTime'] ?? null);
        $payload['suggested_checkout'] = self::clockValue($target['checkoutTime'] ?? null);
        $address = $target['address'] ?? null;
        if (is_array($address)) {
            $street = trim(self::stringValue($address['streetAddress'] ?? ''));
            $locality = trim(self::stringValue($address['addressLocality'] ?? ''));
            $region = trim(self::stringValue($address['addressRegion'] ?? ''));
            $zip = trim(self::stringValue($address['postalCode'] ?? ''));
            $payload['suggested_address'] = [
                'street' => $street,
                'city' => $locality,
                'uf' => $region,
                'cep' => $zip,
            ];
        }
        $payload['suggested_nightly_price'] = self::priceValue($target['priceRange'] ?? null);
        if (isset($target['petsAllowed'])) {
            $payload['suggested_rules']['pets'] = self::boolValue($target['petsAllowed']);
        }
        if (isset($target['smokingAllowed'])) {
            $payload['suggested_rules']['smoking'] = self::boolValue($target['smokingAllowed']);
        }
        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private static function hydrationNodes(string $html): array
    {
        $nodes = [];
        if (preg_match_all('#<script[^>]*type\s*=\s*["\']application/json["\'][^>]*>(.*?)</script>#is', $html, $m) !== false) {
            foreach ($m[1] as $raw) {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded)) {
                    $nodes[] = $decoded;
                }
            }
        }
        foreach (['__NEXT_DATA__', '__INITIAL_STATE__', '__APOLLO_STATE__'] as $var) {
            if (preg_match('#(?:window\.)?' . preg_quote($var, '#') . '\s*=\s*(\{.*?\})\s*;?\s*</script>#is', $html, $m) === 1) {
                $decoded = json_decode($m[1], true);
                if (is_array($decoded)) {
                    $nodes[] = $decoded;
                }
            }
        }
        if (preg_match_all('#<script[^>]*id\s*=\s*["\'](?:data-deferred-state|hydration)[^"\']*["\'][^>]*>(.*?)</script>#is', $html, $m) !== false) {
            foreach ($m[1] as $raw) {
                $decoded = json_decode(trim($raw), true);
                if (is_array($decoded)) {
                    $nodes[] = $decoded;
                }
            }
        }
        return $nodes;
    }

    /** @param list<array<string, mixed>> $nodes @return array<string, mixed> */
    private static function extractFromHydration(array $nodes): array
    {
        $payload = self::emptyPayload();
        foreach ($nodes as $node) {
            foreach (self::flattenNodes($node) as $candidate) {
                if ($payload['title'] === '' && isset($candidate['title']) && is_string($candidate['title'])) {
                    $payload['title'] = $candidate['title'];
                }
                if ($payload['title'] === '' && isset($candidate['name']) && is_string($candidate['name'])) {
                    $payload['title'] = $candidate['name'];
                }
                if ($payload['description'] === '' && isset($candidate['description']) && is_string($candidate['description'])) {
                    $payload['description'] = $candidate['description'];
                }
                if ($payload['photos'] === []) {
                    foreach (['images', 'photoUrls', 'photos', 'imageUrls'] as $key) {
                        if (isset($candidate[$key])) {
                            $payload['photos'] = self::imageList($candidate[$key]);
                            if ($payload['photos'] !== []) {
                                break;
                            }
                        }
                    }
                }
                if ($payload['raw_amenities'] === []) {
                    foreach (['amenities', 'amenityNames', 'listingAmenities'] as $key) {
                        if (isset($candidate[$key])) {
                            $payload['raw_amenities'] = self::amenityList($candidate[$key]);
                            if ($payload['raw_amenities'] !== []) {
                                break;
                            }
                        }
                    }
                }
                if ($payload['suggested_guests'] === null) {
                    $payload['suggested_guests'] = self::intValue($candidate['personCapacity'] ?? $candidate['maxGuests'] ?? $candidate['guests'] ?? null);
                }
                if ($payload['suggested_bedrooms'] === null) {
                    $payload['suggested_bedrooms'] = self::intValue($candidate['bedrooms'] ?? $candidate['numberOfBedrooms'] ?? null);
                }
                if ($payload['suggested_beds'] === null) {
                    $payload['suggested_beds'] = self::intValue($candidate['beds'] ?? $candidate['numberOfBeds'] ?? null);
                }
            }
        }
        return $payload;
    }

    /** @return array<string, string> */
    private static function openGraph(string $html): array
    {
        $og = [];
        if (preg_match_all('#<meta\s+[^>]*property\s*=\s*["\']og:([a-z_]+)["\'][^>]*>#is', $html, $m, PREG_SET_ORDER) !== false) {
            foreach ($m as $tag) {
                if (preg_match('#content\s*=\s*["\']([^"\']*)["\']#is', $tag[0], $c) !== 1) {
                    continue;
                }
                $key = strtolower($tag[1]);
                $value = html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($key === 'image') {
                    $og['image'] ??= [];
                    $og['image'][] = $value;
                } else {
                    $og[$key] = $value;
                }
            }
        }
        return $og;
    }

    /** @param array<string, mixed> $og @return array<string, mixed> */
    private static function extractFromOpenGraph(array $og): array
    {
        $payload = self::emptyPayload();
        $payload['title'] = self::stringValue($og['title'] ?? '');
        $payload['description'] = self::stringValue($og['description'] ?? '');
        $payload['photos'] = self::imageList($og['image'] ?? []);
        return $payload;
    }

    /** Depth-first list of every associative node (arrays of scalars stay as values). @return iterable<array<string, mixed>> */
    private static function flattenNodes(mixed $node): iterable
    {
        if (!is_array($node)) {
            return;
        }
        if (self::isAssoc($node)) {
            yield $node;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                yield from self::flattenNodes($child);
            }
        }
    }

    /** @return list<string> */
    private static function types(array $node): array
    {
        $type = $node['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        return array_values(array_filter(array_map(static fn ($t): string => is_string($t) ? strtolower(preg_replace('/[^a-z]/i', '', $t) ?? '') : '', $types)));
    }

    private static function isAssoc(array $array): bool
    {
        return $array !== [] && array_keys($array) !== range(0, count($array) - 1);
    }

    /** @return list<string> */
    private static function imageList(mixed $value): array
    {
        if (is_string($value)) {
            return [trim($value)];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $url = $item['url'] ?? $item['contentUrl'] ?? $item['src'] ?? null;
                if (is_string($url)) {
                    $out[] = trim($url);
                }
            }
        }
        return array_values(array_filter($out));
    }

    /** @return list<string> */
    private static function amenityList(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', preg_split('/[,;|]/', $value) ?: [])));
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $name = $item['name'] ?? $item['value'] ?? $item['label'] ?? null;
                if (is_string($name)) {
                    $out[] = trim($name);
                }
            }
        }
        return array_values(array_filter($out));
    }

    private static function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            $first = reset($value);
            return is_string($first) ? trim($first) : '';
        }
        return '';
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/\d+/', $value, $m) === 1) {
            $n = (int) $m[0];
            return $n > 0 ? $n : null;
        }
        return null;
    }

    private static function boolValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'sim'], true);
    }

    private static function clockValue(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/\A([01]\d|2[0-3]):([0-5]\d)/', trim($value), $m) === 1) {
            return $m[1] . ':' . $m[2];
        }
        return null;
    }

    private static function priceValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 999999 ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $digits = preg_replace('/[^\d]/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }
        $n = (int) $digits;
        return $n > 0 && $n <= 999999 ? $n : null;
    }

    /** @param array<string, int|bool|string> $flags */
    private static function sanitizeText(string $text, array &$flags): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (self::PLATFORM_PATTERNS as $pattern) {
            $text = (string) preg_replace($pattern, ' ', $text, -1, $count);
            $flags['platform_names'] += $count;
        }
        $text = (string) preg_replace('#\b(?:https?://|www\.)\S+#i', ' ', $text, -1, $count);
        $flags['external_urls'] += $count;
        $text = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /** @return list<string> */
    private static function mapAmenity(string $raw): array
    {
        $normalized = strtolower($raw);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            $normalized = is_string($converted) ? $converted : $normalized;
        }
        $normalized = (string) preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = trim((string) preg_replace('/\s+/', ' ', $normalized));
        if ($normalized === '') {
            return [];
        }
        if (isset(self::AMENITY_MAP[$normalized])) {
            return self::AMENITY_MAP[$normalized];
        }
        $valid = array_flip(Amenities::keys());
        $candidates = [];
        foreach (self::AMENITY_MAP as $phrase => $keys) {
            if (str_contains($normalized, $phrase) || str_contains($phrase, $normalized)) {
                foreach ($keys as $key) {
                    if (isset($valid[$key])) {
                        $candidates[$key] = true;
                    }
                }
            }
        }
        return array_keys($candidates);
    }

    private static function clip(string $text, int $limit): string
    {
        if ($text === '') {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length <= $limit) {
            return $text;
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
    }
}
