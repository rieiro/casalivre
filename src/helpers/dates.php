<?php

declare(strict_types=1);

function parse_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return ($date && $date->format('Y-m-d') === $value) ? $value : null;
    }
    $pattern = current_locale() === 'en' ? '!m/d/Y' : '!d/m/Y';
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat($pattern, $value);
    return ($date && $date->format(current_locale() === 'en' ? 'm/d/Y' : 'd/m/Y') === $value) ? $date->format('Y-m-d') : null;
}

function date_pattern(): string
{
    return current_locale() === 'en' ? 'm/d/Y' : 'd/m/Y';
}

function date_placeholder(): string
{
    return current_locale() === 'en' ? 'mm/dd/yyyy' : 'dd/mm/aaaa';
}

function format_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $iso = parse_date(substr($value, 0, 10));
    if ($iso === null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match)) {
        $iso = $match[1];
    }
    if ($iso === null) {
        return $value;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
    return $date ? $date->format(date_pattern()) : $value;
}

function month_label(DateTimeInterface $month): string
{
    $names = [
        'pt-BR' => [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
    ];
    $locale = function_exists('current_locale') ? current_locale() : 'pt-BR';
    $list = $names[$locale] ?? $names['pt-BR'];
    return $list[(int) $month->format('n')] . ' / ' . $month->format('Y');
}

function br_date(?string $value): string
{
    return format_date($value);
}

function format_time(?string $value): string
{
    $value ??= '';
    if (!preg_match('/^(\d{2}):(\d{2})/', $value, $m)) {
        return $value;
    }
    if (current_locale() !== 'en') {
        return $m[1] . ':' . $m[2];
    }
    $hour = (int) $m[1];
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $hour12 = $hour % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }
    return $hour12 . ':' . $m[2] . ' ' . $suffix;
}

function format_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $date = format_date(substr($value, 0, 10));
    if (preg_match('/(\d{2}:\d{2})/', $value, $m)) {
        return trim($date . ' ' . format_time($m[1]));
    }
    return $date;
}

function nights_between(string $checkin, string $checkout): array
{
    $startIso = parse_date($checkin);
    $endIso = parse_date($checkout);
    if (!$startIso || !$endIso) {
        return [];
    }
    $start = new DateTimeImmutable($startIso);
    $end = new DateTimeImmutable($endIso);
    if ($end <= $start) {
        return [];
    }
    $days = [];
    for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
        $days[] = $cursor->format('Y-m-d');
    }
    return $days;
}

function calendar_months_cap(): int
{
    return max(1, Settings::int('calendar_months'));
}

function property_calendar_months(array $property): int
{
    $cap = calendar_months_cap();
    $own = (int) ($property['calendar_months'] ?? 0);
    if ($own < 1) {
        $own = 6;
    }
    return min($own, $cap);
}

function max_calendar_day(?array $property = null): string
{
    $months = $property ? property_calendar_months($property) : calendar_months_cap();
    return (new DateTimeImmutable('today'))->modify('+' . $months . ' months')->format('Y-m-d');
}
