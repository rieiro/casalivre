<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Gravação do calendário (web POST /imoveis/{hash}/calendario e API POST /api/imoveis/{hash}/calendario).
 * Regra e validação aqui (aplicar, antes em CalendarService::save); run monta os eventos com os params comuns
 * (imovel_id, inicio, fim, resultado). Desfechos (tipo): ok | invalido | conflito; dados['erros'] = [campo => mensagem] nas recusas.
 * A web mostra a mensagem como erro; a API responde 422 (invalido) ou 409 (conflito) com os mesmos erros.
 */
final class CalendarSave
{
    /** Campos de texto (só string) e numéricos (string, int ou float); outro tipo (array, bool...) -> 422. */
    private const TEXTO = ['inicio', 'fim', 'acao', 'day', 'cal_view'];
    private const NUMERO = ['price', 'min_nights'];

    public static function run(array $property, array $input): Resultado
    {
        // Valor bruto conferido antes de qualquer (string)/(int): array em inicio/fim/acao/price etc. vira 422 com o
        // erro no campo (web e API iguais), sem "Array to string conversion" e sem gravar nada.
        foreach (array_merge(self::TEXTO, self::NUMERO) as $campo) {
            $v = $input[$campo] ?? null;
            $ok = $v === null || is_string($v) || (in_array($campo, self::NUMERO, true) && (is_int($v) || is_float($v)));
            if (!$ok) {
                return new Resultado('invalido', t('err.field_type'), ['erros' => [$campo => t('err.field_type')]]);
            }
        }
        $saved = self::aplicar($property, $input);
        $params = [
            'imovel_id' => (int) $property['id'],
            'inicio' => (string) ($input['inicio'] ?? ''),
            'fim' => (string) ($input['fim'] ?? ''),
            'resultado' => $saved['message'],
        ];
        $events = [];
        foreach ($saved['logs'] as $log) {
            $events[] = [$log['code'], $params + $log['extra'], $log['anterior'], $log['atual']];
        }
        return new Resultado($saved['tipo'], $saved['message'], ['erros' => $saved['erros']], $events);
    }

    /**
     * Regra (validação + gravação), movida de CalendarService::save sem mudar a ordem: o limite do horizonte e o
     * mínimo de noites são gravados antes de algumas recusas, como antes.
     */
    private static function aplicar(array $property, array $input): array
    {
        $logs = [];
        // Recusa: tipo invalido (422) ou conflito (409), com o erro por campo; a gravação do limite (acima) fica.
        $done = static function (string $message, ?string $tipo = null, ?string $campo = null) use (&$logs): array {
            return ['message' => $message, 'logs' => $logs, 'tipo' => $tipo ?? 'ok', 'erros' => $campo !== null ? [$campo => $message] : []];
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
            return $done(t('cal.range') . ' ' . format_date($max) . '.', 'invalido', 'inicio');
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
            return $done(t('cal.need_range'), 'invalido', 'inicio');
        }
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }
        if ($start < $today || $end > $max) {
            return $done(t('cal.range') . ' ' . format_date($max) . '.', 'invalido', 'inicio');
        }
        $price = null;
        if ($action === 'preco') {
            if (!isset($input['price']) || $input['price'] === '') {
                return $done(t('cal.need_price'), 'invalido', 'price');
            }
            $price = parse_money((string) $input['price']);
            if ($price < 1 || $price > 999999) {
                return $done(t('cal.price_max'), 'invalido', 'price');
            }
        } elseif (!in_array($action, ['bloquear', 'abrir'], true)) {
            return $done(t('cal.need_range'), 'invalido', 'inicio');
        }
        $result = HouseRepository::applyRange($propertyId, $start, $end, $action, $price);
        if ($result['count'] === 0 && in_array($action, ['bloquear', 'abrir'], true)) {
            return $done(t('cal.sync_locked'), 'conflito', 'inicio');
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
