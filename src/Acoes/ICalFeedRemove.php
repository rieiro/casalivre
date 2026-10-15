<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Exclusão de feed iCal (web POST /imoveis/{hash}/ical/{id}/excluir e API DELETE /api/imoveis/{hash}/ical/{id}).
 * Duas etapas, chamadas pelo adaptador nesta ordem (a mesma de antes no activity_log):
 *   1. remover(): removeFeed -> releaseImported (+ nightAudit); devolve CALENDAR_FEED_REMOVE em eventos;
 *      o adaptador grava com registrar();
 *   2. o adaptador chama CalendarSync::resyncProperty, que grava os próprios CALENDAR_SYNC (por isso depois).
 * Desfechos de remover(): ok | nao_encontrado. Feed inexistente para antes (não libera noites nem grava nada);
 * a API responde 404 e a web redireciona para o calendário com a mensagem de erro.
 */
final class ICalFeedRemove
{
    public static function remover(int $propertyId, int $userId, int $feedId): Resultado
    {
        $gone = HouseRepository::removeFeed($propertyId, $userId, $feedId);
        if (empty($gone['feed'])) {
            return new Resultado('nao_encontrado', t('cal.feed_missing'));
        }
        $released = HouseRepository::nightAudit(HouseRepository::releaseImported($propertyId));
        $events = [[ActivityLog::CALENDAR_FEED_REMOVE, [
            'imovel_id' => $propertyId,
            'feed_id' => $feedId,
        ], [
            'feed' => CalendarSync::logFeed($gone['feed']),
            'link' => CalendarSync::logRef($gone['link'][0]['old']['external_ical_url'] ?? null),
            'noites' => $released['anterior'],
        ], [
            'link' => CalendarSync::logRef($gone['link'][0]['new']['external_ical_url'] ?? null),
            'noites' => $released['atual'],
        ]]];
        return new Resultado('ok', null, [], $events);
    }
}
