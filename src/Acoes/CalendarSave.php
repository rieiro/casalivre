<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Gravação do calendário (web POST /imoveis/{hash}/calendario e API POST /api/imoveis/{hash}/calendario).
 * Regra em CalendarService::save; aqui só se montam os eventos com os params comuns (imovel_id, inicio, fim,
 * resultado). Desfechos (tipo): ok | invalido | conflito; dados['erros'] = [campo => mensagem] nas recusas.
 * A web mostra a mensagem como erro; a API responde 422 (invalido) ou 409 (conflito) com os mesmos erros.
 */
final class CalendarSave
{
    public static function run(array $property, array $input): Resultado
    {
        $saved = CalendarService::save($property, $input);
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
}
