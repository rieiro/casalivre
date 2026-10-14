<?php

declare(strict_types=1);

/** Gravação do calendário do imóvel (movido de public/index.php sem alterar os corpos). */
final class CalendarService
{
    public static function save(array $property, array $input): array
    {
        $logs = [];
        $done = static function (string $message) use (&$logs): array {
            return ['message' => $message, 'logs' => $logs];
        };
        $propertyId = (int) $property['id'];
        $max = max_calendar_day($property);
        $removed = HouseRepository::clearBlockedAfter($propertyId, $max);
        if ($removed !== []) {
            $audit = HouseRepository::nightAudit($removed);
            $logs[] = ['code' => ActivityLog::CALENDAR_UPDATE, 'extra' => ['motivo' => 'limite'], 'anterior' => $audit['anterior'], 'atual' => ['estado' => 'removida']];
        }
        $today = date('Y-m-d');
        $action = (string) ($input['acao'] ?? '');
        $start = parse_date((string) ($input['inicio'] ?? $input['day'] ?? ''));
        $end = parse_date((string) ($input['fim'] ?? '')) ?? $start;
        $hasPrice = isset($input['price']) && $input['price'] !== '';
        $hasMin = isset($input['min_nights']) && $input['min_nights'] !== '';
        if ($start !== null && $end !== null && $end < $start) {
            [$start, $end] = [$end, $start];
        }
        if ($start !== null && $end !== null && ($start < $today || $end > $max)) {
            return $done(t('cal.range') . ' ' . format_date($max) . '.');
        }
        if ($hasMin && $start !== null && $end !== null && ($action === '' || $action === 'salvar')) {
            $audit = HouseRepository::applyMinNights($propertyId, $start, $end, max(1, (int) $input['min_nights']));
            $logs[] = ['code' => ActivityLog::CALENDAR_MIN_NIGHTS, 'extra' => ['minimo_noites' => max(1, (int) $input['min_nights'])], 'anterior' => $audit['anterior'], 'atual' => $audit['atual']];
        } elseif ($hasMin && $start === null) {
            $changed = HouseRepository::updateMinNights($propertyId, max(1, (int) $input['min_nights']));
            $pair = $changed[0] ?? null;
            if ($pair) {
                $logs[] = [
                    'code' => ActivityLog::CALENDAR_MIN_NIGHTS,
                    'extra' => ['minimo_noites' => max(1, (int) $input['min_nights'])],
                    'anterior' => ['minimo_noites' => (int) ($pair['old']['min_nights'] ?? 0)],
                    'atual' => ['minimo_noites' => (int) ($pair['new']['min_nights'] ?? 0)],
                ];
            }
        }
        if ($action === '' || ($action === 'salvar' && !$hasPrice)) {
            return $done(t('cal.saved'));
        }
        if ($action === 'salvar') {
            $action = 'preco';
        }
        if ($start === null || $end === null) {
            return $done(t('cal.need_range'));
        }
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }
        if ($start < $today || $end > $max) {
            return $done(t('cal.range') . ' ' . format_date($max) . '.');
        }
        $price = null;
        if ($action === 'preco') {
            if (!isset($input['price']) || $input['price'] === '') {
                return $done(t('cal.need_price'));
            }
            $price = parse_money((string) $input['price']);
            if ($price < 1 || $price > 999999) {
                return $done(t('cal.price_max'));
            }
        } elseif (!in_array($action, ['bloquear', 'abrir'], true)) {
            return $done(t('cal.need_range'));
        }
        $result = HouseRepository::applyRange($propertyId, $start, $end, $action, $price);
        if ($result['count'] === 0 && in_array($action, ['bloquear', 'abrir'], true)) {
            return $done(t('cal.sync_locked'));
        }
        $code = match ($action) {
            'preco' => ActivityLog::CALENDAR_PRICE,
            'bloquear' => ActivityLog::CALENDAR_BLOCK,
            default => ActivityLog::CALENDAR_UNBLOCK,
        };
        $logs[] = ['code' => $code, 'extra' => $action === 'preco' ? ['diaria' => $price] : [], 'anterior' => $result['anterior'], 'atual' => $result['atual']];
        return $done(t('cal.saved') . ' (' . $result['count'] . ')');
    }
}
