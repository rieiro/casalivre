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
        'ethernet' => ['ethernet'], 'conexao ethernet' => ['ethernet'],
        'workspace' => ['workspace'], 'dedicated workspace' => ['workspace'],
        'espaco de trabalho' => ['workspace'], 'espaco de trabalho exclusivo' => ['workspace'],
        'air conditioning' => ['ac'], 'airconditioning' => ['ac'], 'ar condicionado' => ['ac'],
        'heating' => ['heating'], 'aquecimento' => ['heating'], 'aquecedor' => ['heating'],
        'fireplace' => ['fireplace'], 'lareira' => ['fireplace'], 'lareira interna' => ['fireplace'],
        'ceiling fan' => ['ceiling_fan'], 'ventilador de teto' => ['ceiling_fan'],
        'portable fan' => ['portable_fan'], 'ventilador portatil' => ['portable_fan'],
        'kitchen' => ['kitchen'], 'cozinha' => ['kitchen'], 'stove' => ['stove'], 'fogao' => ['stove'],
        'oven' => ['oven'], 'forno' => ['oven'], 'freezer' => ['freezer'],
        'mini fridge' => ['minifridge'], 'minibar' => ['minifridge'], 'frigobar' => ['minifridge'],
        'dishwasher' => ['dishwasher'], 'maquina de lavar louca' => ['dishwasher'], 'maquina de lavar loucas' => ['dishwasher'],
        'coffee maker' => ['coffee'], 'cafeteira' => ['coffee'],
        'kettle' => ['kettle'], 'chaleira' => ['kettle'], 'chaleira eletrica' => ['kettle'],
        'toaster' => ['toaster'], 'torradeira' => ['toaster'],
        'blender' => ['blender'], 'liquidificador' => ['blender'],
        'microwave' => ['microwave'], 'micro ondas' => ['microwave'],
        'air fryer' => ['airfryer'], 'airfryer' => ['airfryer'],
        'dishes and silverware' => ['dishes', 'cutlery'], 'pratos' => ['dishes'], 'talheres' => ['cutlery'],
        'cooking basics' => ['pots', 'utensils'], 'itens basicos de cozinha' => ['pots', 'utensils'],
        'panelas' => ['pots'], 'utensilios de cozinha' => ['utensils'],
        'wine glasses' => ['wine_glasses'], 'tacas de vinho' => ['wine_glasses'],
        'washer' => ['washer'], 'washing machine' => ['washer'], 'maquina de lavar' => ['washer'], 'lavadora' => ['washer'],
        'dryer' => ['dryer'], 'secadora' => ['dryer'],
        'clothesline' => ['clothesline'], 'varal' => ['clothesline'],
        'iron' => ['iron'], 'ferro de passar' => ['iron'],
        'bed linen' => ['linen'], 'linens' => ['linen'], 'roupas de cama' => ['linen'], 'roupa de cama' => ['linen'],
        'towels' => ['towels'], 'toalhas' => ['towels'],
        'cleaning products' => ['cleaning_products'], 'produtos de limpeza' => ['cleaning_products'],
        'hangers' => ['hangers'], 'cabides' => ['hangers'],
        'extra pillows and blankets' => ['extra_bedding'], 'cobertores e travesseiros extras' => ['extra_bedding'],
        'blackout curtains' => ['blackout'], 'cortinas blackout' => ['blackout'],
        'tv' => ['tv'], 'television' => ['tv'], 'televisao' => ['tv'],
        'sound system' => ['sound_system'], 'sistema de som' => ['sound_system'],
        'game console' => ['game_console'], 'console de videogame' => ['game_console'],
        'books' => ['books'], 'livros' => ['books'],
        'exercise equipment' => ['exercise'], 'equipamento para exercicios' => ['exercise'],
        'gym' => ['gym'], 'fitness center' => ['gym'], 'academia' => ['gym'],
        'bikes' => ['bikes'], 'bicycle' => ['bikes'], 'bicicletas' => ['bikes'],
        'pool table' => ['pool_table'], 'mesa de bilhar' => ['pool_table'],
        'ping pong table' => ['ping_pong'], 'mesa de pingue pongue' => ['ping_pong'],
        'garage' => ['garage'], 'garagem' => ['garage'], 'estacionamento' => ['garage'],
        'free parking on premises' => ['parking_free_onsite'], 'free parking' => ['parking_free_onsite'],
        'estacionamento gratuito no local' => ['parking_free_onsite'],
        'paid parking on premises' => ['parking_paid_onsite'], 'estacionamento pago no local' => ['parking_paid_onsite'],
        'free parking off premises' => ['parking_free_offsite'], 'estacionamento gratuito fora da propriedade' => ['parking_free_offsite'],
        'paid parking off premises' => ['parking_paid_offsite'], 'estacionamento pago fora da propriedade' => ['parking_paid_offsite'],
        'ev charger' => ['ev_charger'], 'carregador de veiculos eletricos' => ['ev_charger'], 'carregador de veiculo eletrico' => ['ev_charger'],
        'elevator' => ['elevator'], 'elevador' => ['elevator'],
        'housekeeping' => ['housekeeping'],
        'pool' => ['pool'], 'piscina' => ['pool'],
        'bbq grill' => ['bbq', 'bbq_tools'], 'barbecue' => ['bbq'], 'churrasqueira' => ['bbq'],
        'outdoor kitchen' => ['outdoor_kitchen'], 'cozinha ao ar livre' => ['outdoor_kitchen'],
        'outdoor dining area' => ['outdoor_dining'], 'area de jantar externa' => ['outdoor_dining'],
        'outdoor furniture' => ['outdoor_furniture'], 'moveis na area externa' => ['outdoor_furniture'],
        'sun loungers' => ['lounger'], 'espreguicadeira' => ['lounger'],
        'hammock' => ['hammock'], 'rede' => ['hammock'],
        'outdoor fireplace' => ['outdoor_fireplace'], 'lareira externa' => ['outdoor_fireplace'],
        'beach essentials' => ['beach_items'], 'itens basicos de praia' => ['beach_items'],
        'balcony' => ['balcony'], 'varanda' => ['balcony'],
        'backyard' => ['yard'], 'quintal' => ['yard'],
        'garden' => ['garden'], 'jardim' => ['garden'],
        'sea view' => ['sea_view'], 'vista para o mar' => ['sea_view'],
        'outdoor shower' => ['outdoor_shower'], 'chuveiro externo' => ['outdoor_shower'],
        'lake access' => ['lake_access'], 'acesso ao lago' => ['lake_access'],
        'beach access' => ['beach_access'], 'acesso a praia' => ['beach_access'],
        'private entrance' => ['private_entrance'], 'entrada privativa' => ['private_entrance'],
        'laundromat nearby' => ['nearby_laundry'], 'lavanderia proxima' => ['nearby_laundry'],
        'gated community' => ['gated_community'], 'condominio fechado' => ['gated_community'],
        'doorman' => ['doorman'], 'porteiro' => ['doorman'],
        'solar panels' => ['solar_panels'], 'paineis solares' => ['solar_panels'],
        'hot water' => ['hot_water'], 'agua quente' => ['hot_water'],
        'hair dryer' => ['hair_dryer'], 'secador de cabelo' => ['hair_dryer'],
        'shampoo' => ['shampoo'], 'xampu' => ['shampoo'],
        'conditioner' => ['conditioner'], 'condicionador' => ['conditioner'],
        'soap' => ['soap'], 'sabonete' => ['soap'],
        'toilet paper' => ['toilet_paper'], 'papel higienico' => ['toilet_paper'],
        'bidet' => ['bidet'], 'bide' => ['bidet'],
        'bathtub' => ['bathtub'], 'banheira' => ['bathtub'],
        'hot tub' => ['hot_tub'], 'banheira de hidromassagem' => ['hot_tub'],
        'sauna' => ['sauna'],
        'crib' => ['crib'], 'berco' => ['crib'],
        'high chair' => ['high_chair'], 'cadeira alta' => ['high_chair'],
        'changing table' => ['changing_table'], 'trocador de bebe' => ['changing_table'],
        'baby bath' => ['baby_bath'], 'banheira infantil' => ['baby_bath'],
        'pack n play' => ['playpen'], 'cercadinho' => ['playpen'],
        'baby gate' => ['baby_gate'], 'portoes de seguranca para bebes' => ['baby_gate'],
        'breakfast' => ['breakfast'], 'cafe da manha' => ['breakfast'],
        'smoke alarm' => ['smoke'], 'detector de fumaca' => ['smoke'],
        'carbon monoxide alarm' => ['co_detector'], 'detector de monoxido de carbono' => ['co_detector'],
        'fire extinguisher' => ['extinguisher'], 'extintor de incendio' => ['extinguisher'],
        'first aid kit' => ['first_aid'], 'kit de primeiros socorros' => ['first_aid'],
        'safe' => ['safe'], 'cofre' => ['safe'],
        'pets allowed' => ['pets'], 'permitido animais' => ['pets'], 'aceita animais' => ['pets'],
        'smoking allowed' => ['smoking'], 'fumo permitido' => ['smoking'], 'permitido fumar' => ['smoking'],
    ];

    /** Unambiguous OTA icon tokens => CasaLivre amenity keys (fallback for non-PT/EN listings). @var array<string, list<string>> */
    private const ICON_AMENITY_MAP = [
        'SYSTEM_WI_FI' => ['wifi'],
        'SYSTEM_WORKSPACE' => ['workspace'],
        'SYSTEM_SNOWFLAKE' => ['ac'],
        'SYSTEM_THERMOMETER' => ['heating'],
        'SYSTEM_FIREPLACE' => ['fireplace'],
        'SYSTEM_STOVE' => ['stove'],
        'SYSTEM_OVEN' => ['oven'],
        'SYSTEM_MICROWAVE' => ['microwave'],
        'SYSTEM_DISHWASHER' => ['dishwasher'],
        'SYSTEM_COFFEE_MAKER' => ['coffee'],
        'SYSTEM_WASHER' => ['washer'],
        'SYSTEM_DRYER' => ['dryer'],
        'SYSTEM_IRON' => ['iron'],
        'SYSTEM_HANGERS' => ['hangers'],
        'SYSTEM_TV' => ['tv'],
        'SYSTEM_EV_CHARGER' => ['ev_charger'],
        'SYSTEM_ELEVATOR' => ['elevator'],
        'SYSTEM_POOL' => ['pool'],
        'SYSTEM_BALCONY' => ['balcony'],
        'SYSTEM_HOT_WATER' => ['hot_water'],
        'SYSTEM_HAIRDRYER' => ['hair_dryer'],
        'SYSTEM_SHAMPOO' => ['shampoo'],
        'SYSTEM_BATHTUB' => ['bathtub'],
        'SYSTEM_HOT_TUB' => ['hot_tub'],
        'SYSTEM_CRIB' => ['crib'],
        'SYSTEM_DETECTOR_SMOKE' => ['smoke'],
        'SYSTEM_DETECTOR_CO' => ['co_detector'],
        'SYSTEM_FIRE_EXTINGUISHER' => ['extinguisher'],
        'SYSTEM_FIRST_AID' => ['first_aid'],
        'SYSTEM_PETS' => ['pets'],
    ];

    /** Hydration __typename values whose local title/description are UI labels, not the listing itself. */
    private const HYDRATION_UI_TYPES = [
        'BasicListItem',
        'AmenityItemsGroup',
        'AmenityItem',
        'StaysPdpDetailGroup',
        'StaysPdpDetailItem',
        'StaysPdpRuleDetails',
        'StaysPdpSafetyAndPropertyDetails',
        'StaysPdpOverview',
        'LoggingEventData',
        'CalendarLoggingEventData',
        'ReviewItem',
        'MediaTour',
        'MediaTourStop',
        'BookItSection',
        'AmenitiesSection',
        'PoliciesSection',
        'LocationSection',
    ];

    /** Platform names/badges never copied into the host's content. */
    private const PLATFORM_PATTERNS = [
        '/\bAirbnb\b/iu', '/\bBooking\.com\b/iu', '/\bBooking\b/iu', '/\bVrbo\b/iu',
        '/\bSuperhost\b/iu', '/\bGenius\b/iu', '/\bPremier Host\b/iu',
    ];

    /** JSON-LD node types that describe the listing itself (not breadcrumbs, carousels or item lists). */
    private const LISTING_TYPES = ['vacationrental', 'lodgingbusiness', 'accommodation', 'house', 'apartment', 'hotel', 'rental'];

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
        $hydration = self::hydrationNodes($html);
        if ($hydration !== []) {
            $hydrated = self::extractFromHydration($hydration);
            if ($flags['source'] === 'none') {
                $payload = $hydrated;
                $flags['source'] = 'hydration';
                $flags['partial'] = false;
            } else {
                $payload = self::mergePayload($payload, $hydrated, true);
            }
        }
        $og = self::openGraph($html);
        if ($og !== []) {
            $ogPayload = self::extractFromOpenGraph($og);
            if ($flags['source'] === 'none') {
                $payload = $ogPayload;
                $flags['source'] = 'opengraph';
                $flags['partial'] = false;
            } else {
                $payload = self::mergePayload($payload, $ogPayload, false);
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
        foreach ((array) $payload['raw_amenities'] as $rawEntry) {
            if (is_array($rawEntry)) {
                $raw = self::sanitizeText((string) ($rawEntry['label'] ?? ''), $flags);
                $icon = (string) ($rawEntry['icon'] ?? '');
            } else {
                $raw = self::sanitizeText((string) $rawEntry, $flags);
                $icon = '';
            }
            if ($raw === '') {
                continue;
            }
            $keys = self::mapAmenity($raw);
            if ($keys === [] && $icon !== '' && isset(self::ICON_AMENITY_MAP[$icon])) {
                $keys = self::ICON_AMENITY_MAP[$icon];
            }
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
                if (array_intersect(self::types($candidate), self::LISTING_TYPES) === []) {
                    continue;
                }
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
                if (array_intersect($types, self::LISTING_TYPES) !== []) {
                    $target = $candidate;
                    break 2;
                }
            }
        }
        if ($target === null) {
            return null;
        }
        $payload = self::emptyPayload();
        $contains = is_array($target['containsPlace'] ?? null) ? $target['containsPlace'] : [];
        $payload['title'] = self::stringValue($target['name'] ?? $target['headline'] ?? '');
        $payload['description'] = self::stringValue($target['description'] ?? '');
        $payload['photos'] = self::imageList($target['image'] ?? $target['photo'] ?? []);
        $payload['raw_amenities'] = self::amenityList($target['amenityFeature'] ?? $target['amenities'] ?? $contains['amenityFeature'] ?? []);
        $payload['suggested_guests'] = self::intValue(
            $target['occupancy']['maxValue']
            ?? $target['occupancy']['value']
            ?? $contains['occupancy']['maxValue']
            ?? $contains['occupancy']['value']
            ?? $target['guestCapacity']
            ?? null
        );
        $payload['suggested_bedrooms'] = self::intValue(
            $target['numberOfRooms']
            ?? $target['numberOfBedrooms']
            ?? $contains['numberOfBedrooms']
            ?? $contains['numberOfRooms']
            ?? null
        );
        $payload['suggested_beds'] = self::intValue($target['numberOfBeds'] ?? $contains['numberOfBeds'] ?? null);
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
        $mediaTourPhotos = [];
        $heroPhotos = [];
        $seenAmenity = [];
        foreach ($nodes as $node) {
            foreach (self::flattenNodes($node) as $candidate) {
                $typename = (string) ($candidate['__typename'] ?? '');
                $isUiNode = $typename !== '' && in_array($typename, self::HYDRATION_UI_TYPES, true);
                if (!$isUiNode) {
                    if ($payload['title'] === '' && isset($candidate['title']) && is_string($candidate['title'])) {
                        $payload['title'] = $candidate['title'];
                    }
                    if ($payload['title'] === '' && isset($candidate['name']) && is_string($candidate['name'])) {
                        $payload['title'] = $candidate['name'];
                    }
                    if ($typename === 'DemandStayListing' && $payload['title'] === '') {
                        $localized = $candidate['description']['name']['localizedString']
                            ?? $candidate['description']['name']['localizedStringWithTranslationPreference']
                            ?? '';
                        if (is_string($localized) && trim($localized) !== '') {
                            $payload['title'] = trim($localized);
                        }
                    }
                    if ($payload['description'] === '' && isset($candidate['description']) && is_string($candidate['description'])) {
                        $payload['description'] = $candidate['description'];
                    }
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
                if (isset($candidate['contextualizedMedia']['edges']) && is_array($candidate['contextualizedMedia']['edges'])) {
                    foreach ($candidate['contextualizedMedia']['edges'] as $edge) {
                        $uri = $edge['node']['uri'] ?? null;
                        if (is_string($uri) && trim($uri) !== '') {
                            $heroPhotos[] = trim($uri);
                        }
                    }
                }
                if ($typename === 'MediaTour' && is_array($candidate['stops'] ?? null) && $candidate['stops'] !== []) {
                    foreach ($candidate['stops'] as $stop) {
                        foreach ((array) ($stop['items'] ?? []) as $item) {
                            $uri = $item['image']['uri'] ?? null;
                            if (is_string($uri) && trim($uri) !== '') {
                                $mediaTourPhotos[] = trim($uri);
                            }
                        }
                    }
                }
                foreach (['amenities', 'amenityNames', 'listingAmenities'] as $key) {
                    if (!isset($candidate[$key])) {
                        continue;
                    }
                    foreach (self::amenityEntries($candidate[$key]) as $entry) {
                        $dedup = is_array($entry) ? ($entry['label'] . '|' . $entry['icon']) : $entry;
                        if (!isset($seenAmenity[$dedup])) {
                            $seenAmenity[$dedup] = true;
                            $payload['raw_amenities'][] = $entry;
                        }
                    }
                }
                if ($payload['suggested_guests'] === null) {
                    $payload['suggested_guests'] = self::intValue(
                        $candidate['personCapacity']
                        ?? $candidate['maxGuestCapacity']
                        ?? $candidate['guestMaxCount']
                        ?? $candidate['maxGuests']
                        ?? $candidate['guests']
                        ?? null
                    );
                }
                if ($payload['suggested_bedrooms'] === null) {
                    $payload['suggested_bedrooms'] = self::intValue($candidate['bedrooms'] ?? $candidate['numberOfBedrooms'] ?? null);
                }
                if ($payload['suggested_beds'] === null) {
                    $payload['suggested_beds'] = self::intValue($candidate['beds'] ?? $candidate['numberOfBeds'] ?? null);
                }
                if ($typename === 'StaysPdpOverview' && is_array($candidate['items'] ?? null)) {
                    self::parseOverviewStrings($candidate['items'], $payload);
                }
                if (($typename === 'StayPetPolicy' || isset($candidate['isAllowed'])) && !isset($payload['suggested_rules']['pets']) && is_bool($candidate['isAllowed'] ?? null)) {
                    $payload['suggested_rules']['pets'] = (bool) $candidate['isAllowed'];
                }
                if ($typename === 'StaysPdpDetailItem') {
                    self::extractHydrationRule($candidate, $payload);
                }
            }
        }
        if ($payload['photos'] === [] && ($heroPhotos !== [] || $mediaTourPhotos !== [])) {
            $payload['photos'] = array_merge($heroPhotos, $mediaTourPhotos);
        } elseif ($mediaTourPhotos !== []) {
            $payload['photos'] = array_merge($payload['photos'], $mediaTourPhotos);
        }
        return $payload;
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $extra @return array<string, mixed> */
    private static function mergePayload(array $base, array $extra, bool $appendPhotos): array
    {
        foreach (['title', 'description'] as $textKey) {
            if ($base[$textKey] === '' && $extra[$textKey] !== '') {
                $base[$textKey] = $extra[$textKey];
            }
        }
        foreach (['suggested_guests', 'suggested_bedrooms', 'suggested_beds', 'suggested_checkin', 'suggested_checkout', 'suggested_address', 'suggested_nightly_price', 'suggested_cleaning_fee'] as $optKey) {
            if ($base[$optKey] === null && $extra[$optKey] !== null) {
                $base[$optKey] = $extra[$optKey];
            }
        }
        if ($base['photos'] === []) {
            $base['photos'] = $extra['photos'];
        } elseif ($appendPhotos && $extra['photos'] !== []) {
            $base['photos'] = array_merge($base['photos'], $extra['photos']);
        }
        if ($extra['raw_amenities'] !== []) {
            $base['raw_amenities'] = array_merge($base['raw_amenities'], $extra['raw_amenities']);
        }
        if ($extra['suggested_rules'] !== []) {
            $base['suggested_rules'] += $extra['suggested_rules'];
        }
        return $base;
    }

    /** @param array<int, mixed> $strings @param array<string, mixed> $payload */
    private static function parseOverviewStrings(array $strings, array &$payload): void
    {
        foreach ($strings as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }
            if ($payload['suggested_guests'] === null && preg_match('/\b(\d+)\s*(?:h[oó]spedes?|guests?|hu[eé]spedes?)\b/iu', $item, $m) === 1) {
                $payload['suggested_guests'] = self::intValue((int) $m[1]);
            }
            if ($payload['suggested_bedrooms'] === null) {
                if (preg_match('/\b(\d+)\s*(?:quartos?|bedrooms?|habitaci[oó]n(?:es)?|dormit[oó]rios?)\b/iu', $item, $m) === 1) {
                    $payload['suggested_bedrooms'] = self::intValue((int) $m[1]);
                } elseif (preg_match('/\b(?:est[uú]dio|studio)\b/iu', $item) === 1) {
                    $payload['suggested_bedrooms'] = 1;
                }
            }
            if ($payload['suggested_beds'] === null && preg_match('/\b(\d+)\s*(?:camas?|beds?)\b/iu', $item, $m) === 1) {
                $payload['suggested_beds'] = self::intValue((int) $m[1]);
            }
        }
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $payload */
    private static function extractHydrationRule(array $item, array &$payload): void
    {
        $type = (string) ($item['type'] ?? '');
        $icon = (string) ($item['icon'] ?? '');
        if ($type === 'HOUSE_RULES_PETS' && !isset($payload['suggested_rules']['pets'])) {
            $payload['suggested_rules']['pets'] = $icon !== 'SYSTEM_NO_PETS';
        } elseif ($type === 'HOUSE_RULES_SMOKING_ALLOWED' && !isset($payload['suggested_rules']['smoking'])) {
            $payload['suggested_rules']['smoking'] = $icon !== 'SYSTEM_SMOKING_NOT_ALLOWED';
        } elseif ($type === 'HOUSE_RULES_EVENTS_ALLOWED' && !isset($payload['suggested_rules']['parties'])) {
            $payload['suggested_rules']['parties'] = $icon !== 'SYSTEM_NO_EVENTS';
        }
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
        self::parseOverviewStrings([$payload['title']], $payload);
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
        $out = [];
        foreach (self::amenityEntries($value) as $entry) {
            $out[] = is_array($entry) ? (string) $entry['label'] : (string) $entry;
        }
        return $out;
    }

    /**
     * Extracts available amenities, preserving optional icon metadata when provided (e.g. Airbnb AmenityItem).
     * Unavailable items (`available === false`) are excluded.
     *
     * @return list<string|array{label: string, icon: string}>
     */
    private static function amenityEntries(mixed $value): array
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
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            } elseif (is_array($item)) {
                if (array_key_exists('available', $item) && $item['available'] !== true) {
                    continue;
                }
                $name = $item['name'] ?? $item['value'] ?? $item['label'] ?? $item['title'] ?? null;
                if (!is_string($name) || trim($name) === '') {
                    continue;
                }
                $icon = isset($item['icon']) && is_string($item['icon']) ? trim($item['icon']) : '';
                $out[] = $icon !== '' ? ['label' => trim($name), 'icon' => $icon] : trim($name);
            }
        }
        return $out;
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
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
            if (is_string($converted)) {
                $normalized = str_replace(["'", '"', '^', '~', '`'], '', $converted);
            }
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
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/', $normalized) === 1) {
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
