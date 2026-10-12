<?php

declare(strict_types=1);

final class Rooms
{
    public static function forForm(array $posted, ?array $property): array
    {
        if (isset($posted['rooms']) && is_array($posted['rooms'])) {
            $loose = self::loose($posted['rooms']);
            if ($loose !== []) {
                return $loose;
            }
        }
        $stored = self::decode((string) ($property['rooms'] ?? ''));
        if ($stored !== []) {
            return $stored;
        }
        $bedrooms = (int) ($property['bedrooms'] ?? 1);
        $beds = (int) ($property['beds'] ?? 1);
        return self::synthesize($bedrooms, $beds);
    }

    public static function parse(array $input): array
    {
        $raw = $input['rooms'] ?? null;
        if (!is_array($raw) || $raw === []) {
            $bedrooms = (int) ($input['bedrooms'] ?? 0);
            $beds = (int) ($input['beds'] ?? 0);
            if ($bedrooms < 1 || $beds < 1 || $bedrooms > 999 || $beds > 999) {
                return ['error' => t('err.rooms')];
            }
            $raw = self::synthesize($bedrooms, $beds);
        }
        $rooms = [];
        $beds = 0;
        foreach ($raw as $room) {
            if (!is_array($room)) {
                return ['error' => t('err.rooms')];
            }
            $list = [];
            foreach (($room['beds'] ?? []) as $bed) {
                if (!is_array($bed)) {
                    return ['error' => t('err.rooms')];
                }
                $clean = self::bed((string) ($bed['type'] ?? ''), (string) ($bed['variant'] ?? ''));
                if ($clean === null) {
                    return ['error' => t('err.rooms')];
                }
                $list[] = $clean;
                $beds++;
            }
            if ($list === []) {
                return ['error' => t('err.rooms')];
            }
            $rooms[] = ['beds' => $list];
        }
        if (count($rooms) > 999 || $beds > 999) {
            return ['error' => t('err.rooms')];
        }
        return [
            'rooms' => $rooms,
            'bedrooms' => count($rooms),
            'beds' => $beds,
            'json' => self::encode($rooms),
        ];
    }

    public static function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            return [];
        }
        return self::loose($parsed);
    }

    public static function describe(array $rooms): array
    {
        $lines = [];
        foreach (array_values($rooms) as $i => $room) {
            if (!is_array($room)) {
                continue;
            }
            $names = [];
            foreach ($room['beds'] ?? [] as $bed) {
                if (!is_array($bed)) {
                    continue;
                }
                $names[] = self::label((string) ($bed['type'] ?? ''), (string) ($bed['variant'] ?? ''));
            }
            if ($names !== []) {
                $lines[] = t('form.room') . ' ' . ($i + 1) . ': ' . implode(', ', $names);
            }
        }
        return $lines;
    }

    public static function label(string $type, string $variant): string
    {
        if ($type === 'bunk') {
            return t('form.bed_bunk');
        }
        if ($type === 'single') {
            return $variant === 'widow' ? t('form.bed_widow') : t('form.bed_single');
        }
        if ($type === 'double' && $variant === 'queen') {
            return t('form.bed_queen');
        }
        if ($type === 'double' && $variant === 'king') {
            return t('form.bed_king');
        }
        return t('form.bed_double');
    }

    private static function synthesize(int $bedrooms, int $beds): array
    {
        $bedrooms = max(1, min(999, $bedrooms));
        $beds = max($bedrooms, min(999, max(1, $beds)));
        $base = intdiv($beds, $bedrooms);
        $extra = $beds % $bedrooms;
        $rooms = [];
        for ($i = 0; $i < $bedrooms; $i++) {
            $count = $base + ($i < $extra ? 1 : 0);
            $list = [];
            for ($n = 0; $n < $count; $n++) {
                $list[] = ['type' => 'single', 'variant' => 'common'];
            }
            $rooms[] = ['beds' => $list];
        }
        return $rooms;
    }

    private static function loose(array $raw): array
    {
        $rooms = [];
        $beds = 0;
        foreach ($raw as $room) {
            if (!is_array($room) || count($rooms) >= 999) {
                continue;
            }
            $list = [];
            foreach (($room['beds'] ?? []) as $bed) {
                if (!is_array($bed) || $beds >= 999) {
                    continue;
                }
                $clean = self::bed((string) ($bed['type'] ?? ''), (string) ($bed['variant'] ?? ''));
                if ($clean === null) {
                    continue;
                }
                $list[] = $clean;
                $beds++;
            }
            if ($list !== []) {
                $rooms[] = ['beds' => $list];
            }
        }
        return $rooms;
    }

    private static function bed(string $type, string $variant): ?array
    {
        if ($type === 'bunk') {
            return ['type' => 'bunk', 'variant' => ''];
        }
        if ($variant === '') {
            $variant = 'common';
        }
        if ($type === 'single' && ($variant === 'common' || $variant === 'widow')) {
            return ['type' => 'single', 'variant' => $variant];
        }
        if ($type === 'double' && ($variant === 'common' || $variant === 'queen' || $variant === 'king')) {
            return ['type' => 'double', 'variant' => $variant];
        }
        return null;
    }

    private static function encode(array $rooms): string
    {
        return json_encode($rooms, JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
