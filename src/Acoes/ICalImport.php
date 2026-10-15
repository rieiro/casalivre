<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';

/**
 * Cadastro + primeira importação de um feed iCal (web POST /imoveis/{hash}/ical/importar e
 * API POST /api/imoveis/{hash}/ical/importar). Mesma regra e mesmos logs nos dois canais.
 *
 * Desfechos (tipo): vazio | title | limit (422) | duplicate (409) | ok | nok. dados: importadas (int) e,
 * nas recusas, erros = [campo => mensagem] (campos external_ical_url / feed_title).
 * Logs: recusa não grava nada; com o feed salvo, CALENDAR_FEED_ADD e depois CALENDAR_SYNC (resultado ok/nok).
 * O corpo .ics enviado pelo cliente nunca é usado: só o que o servidor baixa (exported_calendar_body/fetch).
 */
final class ICalImport
{
    public static function run(array $property, int $userId, string $url, string $feedTitle): Resultado
    {
        $propertyId = (int) $property['id'];
        if ($url === '') {
            return self::recusa('vazio', 'external_ical_url', t('cal.url_required'));
        }
        $error = HouseRepository::addFeed($propertyId, $userId, $url, $feedTitle);
        if ($error === 'limit') {
            return self::recusa('limit', 'external_ical_url', t('cal.feed_limit'));
        }
        if ($error === 'duplicate') {
            return self::recusa('duplicate', 'external_ical_url', t('cal.feed_duplicate'));
        }
        if ($error === 'title') {
            return self::recusa('title', 'feed_title', t('cal.feed_title_required'));
        }
        $count = 0;
        $body = exported_calendar_body($url) ?? CalendarSync::fetch($url);
        $ok = is_string($body) && $body !== '';
        if ($ok) {
            $count = import_ics($propertyId, $body);
        }
        $now = date('Y-m-d H:i:s');
        // calendar_feeds tem UNIQUE (property_id, url): há no máximo um feed com essa URL.
        foreach (HouseRepository::feedsWithUrl($propertyId) as $feed) {
            if ($feed['url'] === $url) {
                HouseRepository::markFeed((int) $feed['id'], $ok ? 'OK' : 'NOK', $now);
                break;
            }
        }
        $message = $ok ? t('cal.imported') . ' ' . $count . ' ' . t('cal.blocked_dates') : t('cal.import_fail');
        $events = [
            [
                ActivityLog::CALENDAR_FEED_ADD,
                ['imovel_id' => $propertyId, 'titulo' => $feedTitle, 'url' => CalendarSync::logRef($url), 'resultado' => $message],
                null,
                is_array($error) ? ['feed' => CalendarSync::logFeed($error['feed']), 'link' => CalendarSync::logRef($error['link'][0]['new']['external_ical_url'] ?? null)] : null,
            ],
            [ActivityLog::CALENDAR_SYNC, [
                'imovel_id' => $propertyId,
                'url' => CalendarSync::logRef($url),
                'novas' => $count,
                'resultado' => $ok ? 'ok' : 'nok',
            ]],
        ];
        return new Resultado($ok ? 'ok' : 'nok', $message, ['importadas' => $count], $events);
    }

    private static function recusa(string $tipo, string $campo, string $message): Resultado
    {
        return new Resultado($tipo, $message, ['importadas' => 0, 'erros' => [$campo => $message]]);
    }
}
