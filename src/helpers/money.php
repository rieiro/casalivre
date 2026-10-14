<?php

declare(strict_types=1);

function money(int $amount): string
{
    if (current_locale() === 'en') {
        return '$' . number_format($amount, 0, '.', ',');
    }
    if (current_locale() === 'es') {
        return number_format($amount, 0, ',', '.') . ' €';
    }
    return 'R$ ' . number_format($amount, 0, ',', '.');
}

function money_input(int $amount): string
{
    return (string) $amount;
}

function money_brl(int $amount): string
{
    return 'R$ ' . number_format(max(0, $amount), 2, ',', '.');
}

function parse_money(string $raw): int
{
    $raw = trim(str_replace(['R$', '€', '$', "\u{00A0}", ' '], '', trim($raw)));
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/', $raw, $match)) {
        if (($match[2] ?? '') !== '' && (int) $match[2] !== 0) {
            return 0;
        }
        return (int) str_replace('.', '', $match[1]);
    }
    if (preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/', $raw, $match)) {
        if (($match[2] ?? '') !== '' && (int) $match[2] !== 0) {
            return 0;
        }
        return (int) str_replace(',', '', $match[1]);
    }
    return 0;
}

function stay_quote(array $property, array $nights, ?array $byDay = null): ?array
{
    if ($nights === []) {
        return null;
    }
    $byDay ??= HouseRepository::calendarForNights((int) $property['id'], $nights);
    $custom = (int) ($byDay[$nights[0]]['min_nights'] ?? 0);
    $minimum = $custom > 0 ? $custom : (int) $property['min_nights'];
    if (count($nights) < $minimum) {
        return null;
    }
    $max = max_calendar_day($property);
    $total = 0;
    foreach ($nights as $day) {
        if ($day > $max || !empty($byDay[$day]['blocked'])) {
            return null;
        }
        $total += (int) ($byDay[$day]['price'] ?? $property['nightly_price']);
    }
    $cleaning = max(0, (int) ($property['cleaning_fee'] ?? 0));
    return [
        'nights' => count($nights),
        'cleaning' => $cleaning,
        'total' => $total + $cleaning,
        'nightly' => (int) round($total / count($nights)),
    ];
}
