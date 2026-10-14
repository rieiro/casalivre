<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Exclusão de feed iCal (web POST /imoveis/{hash}/ical/{id}/excluir e API DELETE /api/imoveis/{hash}/ical/{id}).
 * Ordem: removeFeed -> releaseImported (+ nightAudit) -> CALENDAR_FEED_REMOVE -> resyncProperty.
 * Desfechos: ok (dados['ressincronizados']) | nao_encontrado.
 *
 * Diferença web/API preservada de propósito (SNAPSHOT, ver tests/standalone/char_calendario_test.php):
 * - $seguirSemFeed (web = true): com feed inexistente a web ainda libera as noites importadas e ressincroniza,
 *   sem gravar CALENDAR_FEED_REMOVE, e o adaptador redireciona (302). A API (false) para antes e responde 404.
 * Exceção à regra "o adaptador registra": aqui o CALENDAR_FEED_REMOVE é gravado dentro da ação, antes do
 * resync, porque o resync grava os próprios eventos (CALENDAR_SYNC) e a ordem no activity_log é preservada.
 * O Resultado devolvido não tem eventos (registrar() no adaptador é um no-op).
 */
final class ICalFeedRemove
{
    public static function run(int $propertyId, int $userId, int $feedId, bool $seguirSemFeed): Resultado
    {
        $gone = HouseRepository::removeFeed($propertyId, $userId, $feedId);
        if (empty($gone['feed']) && !$seguirSemFeed) {
            return new Resultado('nao_encontrado', 'Feed não encontrado.');
        }
        $released = HouseRepository::nightAudit(HouseRepository::releaseImported($propertyId));
        $events = [];
        if ($gone['feed']) {
            $events[] = [ActivityLog::CALENDAR_FEED_REMOVE, [
                'imovel_id' => $propertyId,
                'feed_id' => $feedId,
            ], [
                'feed' => CalendarSync::logFeed($gone['feed']),
                'link' => CalendarSync::logRef($gone['link'][0]['old']['external_ical_url'] ?? null),
                'noites' => $released['anterior'],
            ], [
                'link' => CalendarSync::logRef($gone['link'][0]['new']['external_ical_url'] ?? null),
                'noites' => $released['atual'],
            ]];
        }
        (new Resultado('ok', null, [], $events))->registrar();
        $resync = CalendarSync::resyncProperty($propertyId);
        return new Resultado($gone['feed'] ? 'ok' : 'nao_encontrado', null, ['ressincronizados' => $resync]);
    }
}
