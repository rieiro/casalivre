<?php

declare(strict_types=1);

final class Amenities
{
    public const ICONS = [
        'wifi' => '📶',
        'ethernet' => '🔌',
        'workspace' => '💻',
        'ac' => '❄️',
        'heating' => '♨️',
        'fireplace' => '🔥',
        'ceiling_fan' => '🌀',
        'portable_fan' => '💨',
        'kitchen' => '🍳',
        'stove' => '🍳',
        'oven' => '🥘',
        'freezer' => '🧊',
        'minifridge' => '🥤',
        'dishwasher' => '🍽️',
        'coffee' => '☕',
        'kettle' => '🫖',
        'toaster' => '🍞',
        'blender' => '🍹',
        'bread_maker' => '🥖',
        'rice_cooker' => '🍚',
        'microwave' => '🍿',
        'airfryer' => '🍟',
        'pots' => '🍲',
        'dishes' => '🍽️',
        'cutlery' => '🍴',
        'utensils' => '🥄',
        'baking_pan' => '🥧',
        'wine_glasses' => '🍷',
        'washer' => '🧺',
        'dryer' => '💨',
        'clothesline' => '👕',
        'iron' => '👔',
        'linen' => '🛏️',
        'towels' => '🧖',
        'cleaning_products' => '🧹',
        'hangers' => '🧥',
        'extra_bedding' => '🛋️',
        'blackout' => '🌑',
        'mosquito_net' => '🦟',
        'tv' => '📺',
        'cinema' => '🎬',
        'sound_system' => '🔊',
        'turntable' => '🎵',
        'piano' => '🎹',
        'game_console' => '🎮',
        'arcade' => '🕹️',
        'games' => '🎲',
        'books' => '📚',
        'exercise' => '🏋️',
        'gym' => '🏋️‍♂️',
        'bikes' => '🚲',
        'pool_table' => '🎱',
        'ping_pong' => '🏓',
        'bowling' => '🎳',
        'climbing' => '🧗',
        'skate_ramp' => '🛹',
        'garage' => '🚗',
        'parking_free_onsite' => '🅿️',
        'parking_paid_onsite' => '🅿️',
        'parking_free_offsite' => '🚙',
        'parking_paid_offsite' => '🚙',
        'ev_charger' => '⚡',
        'elevator' => '🛗',
        'housekeeping' => '🧹',
        'pool' => '🏊',
        'bbq' => '🥩',
        'bbq_tools' => '🍴',
        'outdoor_kitchen' => '🍳',
        'outdoor_dining' => '🍽️',
        'outdoor_furniture' => '🪑',
        'lounger' => '🏖️',
        'hammock' => '🌴',
        'outdoor_fireplace' => '🔥',
        'beach_items' => '🏖️',
        'balcony' => '🌅',
        'yard' => '🏡',
        'garden' => '🌳',
        'sea_view' => '🌊',
        'outdoor_shower' => '🚿',
        'lake_access' => '⛵',
        'beach_access' => '🏖️',
        'private_entrance' => '🚪',
        'nearby_laundry' => '🧺',
        'gated_community' => '🏘️',
        'doorman' => '🧑‍✈️',
        'solar_panels' => '☀️',
        'bathroom' => '🚽',
        'hot_water' => '🚿',
        'soap' => '🧼',
        'body_soap' => '🧼',
        'shower_gel' => '🧴',
        'shampoo' => '🧴',
        'conditioner' => '🧴',
        'hair_dryer' => '💨',
        'toilet_paper' => '🧻',
        'bidet' => '🚿',
        'bathtub' => '🛁',
        'hot_tub' => '🛁',
        'sauna' => '🧖',
        'crib' => '👶',
        'baby_monitor' => '📟',
        'changing_table' => '🚼',
        'baby_bath' => '🛁',
        'high_chair' => '🪑',
        'kids_cutlery' => '🍴',
        'playpen' => '🧸',
        'baby_gate' => '🚪',
        'outlet_covers' => '🔌',
        'corner_guards' => '🛡️',
        'window_guard' => '🪟',
        'fireplace_guard' => '🛡️',
        'kids_bikes' => '🚲',
        'kids_toys' => '🧸',
        'board_games' => '🎲',
        'kids_playroom' => '🎈',
        'breakfast' => '🥐',
        'smoke' => '🚨',
        'co_detector' => '📟',
        'extinguisher' => '🧯',
        'first_aid' => '🩹',
        'safe' => '🔒',
        'noise' => '🌙',
        'smoking' => '🚬',
        'pets' => '🐾',
        'sand_shower' => '🚿',
        'wet_elevator' => '🚫',
        'parties' => '🎉',
    ];

    public static function icon(string $key): string
    {
        return self::ICONS[$key] ?? '✓';
    }
    public const GROUPS = [
        'internet' => ['wifi', 'ethernet', 'workspace'],
        'climate' => ['ac', 'heating', 'fireplace', 'ceiling_fan', 'portable_fan'],
        'kitchen' => ['kitchen', 'stove', 'oven', 'freezer', 'minifridge', 'dishwasher', 'coffee', 'kettle', 'toaster', 'blender', 'bread_maker', 'rice_cooker', 'microwave', 'airfryer', 'pots', 'dishes', 'cutlery', 'utensils', 'baking_pan', 'wine_glasses'],
        'laundry' => ['washer', 'dryer', 'clothesline', 'iron', 'linen', 'towels', 'cleaning_products'],
        'bedroom' => ['hangers', 'extra_bedding', 'blackout', 'mosquito_net'],
        'fun' => ['tv', 'cinema', 'sound_system', 'turntable', 'piano', 'game_console', 'arcade', 'games', 'books'],
        'sport' => ['exercise', 'gym', 'bikes', 'pool_table', 'ping_pong', 'bowling', 'climbing', 'skate_ramp'],
        'access' => ['garage', 'parking_free_onsite', 'parking_paid_onsite', 'parking_free_offsite', 'parking_paid_offsite', 'ev_charger', 'elevator'],
        'service' => ['housekeeping'],
        'outside' => ['pool', 'bbq', 'bbq_tools', 'outdoor_kitchen', 'outdoor_dining', 'outdoor_furniture', 'lounger', 'hammock', 'outdoor_fireplace', 'beach_items', 'balcony', 'yard', 'garden', 'sea_view', 'outdoor_shower'],
        'location' => ['lake_access', 'beach_access', 'private_entrance', 'nearby_laundry'],
        'building' => ['gated_community', 'doorman', 'solar_panels'],
        'bath' => ['bathroom', 'hot_water', 'soap', 'body_soap', 'shower_gel', 'shampoo', 'conditioner', 'hair_dryer', 'toilet_paper', 'bidet', 'bathtub', 'hot_tub', 'sauna'],
        'family' => ['crib', 'baby_monitor', 'changing_table', 'baby_bath', 'high_chair', 'kids_cutlery', 'playpen', 'baby_gate', 'outlet_covers', 'corner_guards', 'window_guard', 'fireplace_guard', 'kids_bikes', 'kids_toys', 'board_games', 'kids_playroom', 'breakfast'],
        'safety' => ['smoke', 'co_detector', 'extinguisher', 'first_aid', 'safe'],
        'rules' => ['noise', 'smoking', 'pets', 'sand_shower', 'wet_elevator', 'parties'],
    ];

    public static function keys(): array
    {
        $keys = [];
        foreach (self::GROUPS as $group) {
            foreach ($group as $key) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function fromInput(array $input): array
    {
        $selected = [];
        $posted = is_array($input['amenity'] ?? null) ? $input['amenity'] : [];
        foreach (self::keys() as $key) {
            $legacy = in_array($key, ['pool', 'bbq', 'garage'], true) && !empty($input[$key]);
            if (!empty($posted[$key]) || $legacy) {
                $selected[$key] = 1;
            }
        }
        if (is_array($input['amenity'] ?? null) && empty($posted['noise'])) {
            $selected['noise'] = 0;
        }
        return $selected;
    }

    public static function selected(?array $property): array
    {
        $decoded = json_decode((string) ($property['amenities'] ?? ''), true);
        $selected = is_array($decoded) ? $decoded : [];
        foreach (['pool', 'bbq', 'garage'] as $key) {
            if (!empty($property[$key])) {
                $selected[$key] = 1;
            }
        }
        if (!array_key_exists('noise', $selected)) {
            $selected['noise'] = 1;
            $selected['noise_from'] = $selected['noise_from'] ?? '22:00';
            $selected['noise_until'] = $selected['noise_until'] ?? '07:00';
        }
        return $selected;
    }

    public static function clock(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $match)) {
            return null;
        }
        return $match[1] . ':' . $match[2];
    }

    public const RULE_KEYS = ['noise', 'smoking', 'pets', 'sand_shower', 'wet_elevator', 'parties'];

    public static function ruleKeys(): array
    {
        return self::RULE_KEYS;
    }

    public static function isRule(string $key): bool
    {
        return in_array($key, self::RULE_KEYS, true);
    }

    public static function amenityKeys(): array
    {
        $keys = [];
        foreach (self::GROUPS as $group => $items) {
            if ($group === 'rules') {
                continue;
            }
            foreach ($items as $key) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function rules(?array $property): array
    {
        $selected = self::selected($property);
        $quietEnabled = !empty($selected['noise']);
        $from = self::clock((string) ($selected['noise_from'] ?? '')) ?? '22:00';
        $until = self::clock((string) ($selected['noise_until'] ?? '')) ?? '07:00';

        return [
            'quiet_hours' => [
                'enabled' => $quietEnabled,
                'from' => $from,
                'until' => $until,
                'description' => $quietEnabled
                    ? sprintf('Horário de Silêncio das %s às %s (proibido fazer barulho / quiet hours - no noise allowed)', $from, $until)
                    : 'Sem horário específico de silêncio cadastrado',
            ],
            'smoking_allowed' => !empty($selected['smoking']),
            'pets_allowed' => !empty($selected['pets']),
            'parties_allowed' => !empty($selected['parties']),
            'sand_shower_required' => !empty($selected['sand_shower']),
            'wet_clothes_in_elevator_prohibited' => !empty($selected['wet_elevator']),
        ];
    }

    public static function noiseWindow(?array $property): string
    {
        $selected = self::selected($property);
        if (empty($selected['noise'])) {
            return '';
        }
        $from = self::clock((string) ($selected['noise_from'] ?? ''));
        $until = self::clock((string) ($selected['noise_until'] ?? ''));
        if ($from === null || $until === null) {
            return '';
        }
        return $from . '–' . $until;
    }
}
