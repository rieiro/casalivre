<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Gravação do calendário (web POST /imoveis/{hash}/calendario e API POST /api/imoveis/{hash}/calendario).
 * Regra em CalendarService::save; aqui só se montam os eventos com os params comuns (imovel_id, inicio, fim,
 * resultado). O desfecho é sempre 'ok' com a mensagem do save, inclusive em erro de validação (SNAPSHOT: a API
 * responde 200 {ok:true} e a web re-renderiza a tela; só a mensagem indica o erro).
 */
final class CalendarSave
{
    public static function run(array $property, array $input): Resultado
    {
        $saved = save_calendar($property, $input);
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
        return new Resultado('ok', $saved['message'], [], $events);
    }
}
