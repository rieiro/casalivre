<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/TextoBruto.php';

/**
 * Cadastro de um feed iCal (web POST /imoveis/{hash}/ical/importar e
 * API POST /api/imoveis/{hash}/ical/importar). Não baixa o .ics: o worker sincroniza.
 *
 * Desfechos (tipo): vazio | tipo | url | title | limit (422) | duplicate (409) | ok.
 * url e título chegam BRUTOS. Só https:// na porta 443, até CalendarSync::MAX_URL_LENGTH.
 * Logs: recusa não grava nada; com o feed salvo, só CALENDAR_FEED_ADD (o CALENDAR_SYNC é do worker).
 */
final class ICalImport
{
    public static function run(array $property, int $userId, mixed $url, mixed $feedTitle): Resultado
    {
        $propertyId = (int) $property['id'];
        if (!TextoBruto::ok($url)) {
            return self::recusa('tipo', 'external_ical_url', t('err.field_type'));
        }
        if (!TextoBruto::ok($feedTitle)) {
            return self::recusa('tipo', 'feed_title', t('err.field_type'));
        }
        $url = trim((string) $url);
        $feedTitle = trim((string) $feedTitle);
        if ($url === '') {
            return self::recusa('vazio', 'external_ical_url', t('cal.url_required'));
        }
        if (!CalendarSync::isHttpsUrl($url)) {
            return self::recusa('url', 'external_ical_url', t('cal.url_https'));
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
        if ($error === 'url') {
            return self::recusa('url', 'external_ical_url', t('cal.url_https'));
        }
        $message = t('cal.feed_added');
        $events = [[
            ActivityLog::CALENDAR_FEED_ADD,
            ['imovel_id' => $propertyId, 'titulo' => $feedTitle, 'url' => CalendarSync::logRef($url), 'resultado' => $message],
            null,
            is_array($error) ? ['feed' => CalendarSync::logFeed($error['feed']), 'link' => CalendarSync::logRef($error['link'][0]['new']['external_ical_url'] ?? null)] : null,
        ]];
        return new Resultado('ok', $message, ['importadas' => 0], $events);
    }

    private static function recusa(string $tipo, string $campo, string $message): Resultado
    {
        return new Resultado($tipo, $message, ['importadas' => 0, 'erros' => [$campo => $message]]);
    }
}
