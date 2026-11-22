<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/TextoBruto.php';
require_once dirname(__DIR__) . '/OtaFeedGate.php';
require_once dirname(__DIR__) . '/OtaPlatformRegistry.php';

/**
 * Cadastro de um feed iCal (web POST /imoveis/{hash}/ical/importar e
 * API POST /api/imoveis/{hash}/ical/importar).
 *
 * `mode = 'ota'` requires platform, property_url and consent and runs the platform-bound
 * pre-save gate; `mode = 'sync_only'` keeps PMS/generic feeds syncing without badge/credits.
 */
final class ICalImport
{
    public static function run(
        array $property,
        int $userId,
        mixed $url,
        mixed $feedTitle,
        mixed $mode = 'sync_only',
        mixed $platform = '',
        mixed $propertyUrl = '',
        mixed $consent = false
    ): Resultado {
        $propertyId = (int) $property['id'];
        if (!TextoBruto::ok($url)) {
            return self::recusa('tipo', 'external_ical_url', t('err.field_type'));
        }
        if (!TextoBruto::ok($feedTitle)) {
            return self::recusa('tipo', 'feed_title', t('err.field_type'));
        }
        foreach ([$mode, $platform, $propertyUrl] as $value) {
            if (!TextoBruto::ok($value)) {
                return self::recusa('tipo', 'mode', t('err.field_type'));
            }
        }
        $url = trim((string) $url);
        $feedTitle = trim((string) $feedTitle);
        $mode = strtolower(trim((string) $mode));
        if ($mode === '') {
            $mode = 'sync_only';
        }
        if (!in_array($mode, ['ota', 'sync_only'], true)) {
            return self::recusa('mode', 'mode', t('ota.err_mode'));
        }
        if ($url === '') {
            return self::recusa('vazio', 'external_ical_url', t('cal.url_required'));
        }
        if (!CalendarSync::isHttpsUrl($url)) {
            return self::recusa('url', 'external_ical_url', t('cal.url_https'));
        }

        try {
            $error = HouseRepository::addFeed(
                $propertyId,
                $userId,
                $url,
                $feedTitle,
                $mode,
                trim((string) $platform),
                trim((string) $propertyUrl),
                in_array($consent, [true, 1, '1', 'true', 'on'], true)
            );
        } catch (OtaFeedGateException $e) {
            return self::recusa('gate', $e->field, $e->getMessage());
        } catch (RuntimeException $e) {
            return self::recusa('fetch', 'external_ical_url', $e->getMessage());
        }
        if ($error === 'missing') {
            return self::recusa('missing', 'external_ical_url', t('cal.feed_missing'));
        }
        if ($error === 'limit') {
            return self::recusa('limit', 'external_ical_url', t('cal.feed_limit'));
        }
        if ($error === 'duplicate') {
            return self::recusa('duplicate', 'external_ical_url', t('cal.duplicate_feed_global'));
        }
        if ($error === 'daily_limit') {
            return self::recusa('limit', 'external_ical_url', t('ota.err_daily_limit'));
        }
        if ($error === 'title') {
            return self::recusa('title', 'feed_title', t('cal.feed_title_required'));
        }
        if ($error === 'url') {
            return self::recusa('url', 'external_ical_url', t('cal.url_https'));
        }
        if ($error === 'mode') {
            return self::recusa('mode', 'mode', t('ota.err_mode'));
        }

        $message = t('cal.feed_added');
        $events = [[
            ActivityLog::CALENDAR_FEED_ADD,
            [
                'imovel_id' => $propertyId,
                'titulo' => $feedTitle,
                'url' => CalendarSync::logRef($url),
                'modo' => $mode,
                'plataforma' => (string) $platform,
            ],
            null,
            is_array($error) ? [
                'feed' => CalendarSync::logFeed($error['feed']),
                'link' => CalendarSync::logRef($error['link'][0]['new']['external_ical_url'] ?? null),
            ] : null,
        ]];
        if ($mode === 'ota') {
            $events[] = [ActivityLog::OTA_VERIFY_ADD, [
                'imovel_id' => $propertyId,
                'plataforma' => (string) $platform,
                'url' => CalendarSync::logRef($url),
            ]];
        }
        return new Resultado('ok', $message, [
            'importadas' => is_array($error) ? (int) ($error['imported'] ?? 0) : 0,
            'mode' => $mode,
            'review_status' => is_array($error) ? (string) ($error['review_status'] ?? '') : '',
        ], $events);
    }

    private static function recusa(string $tipo, string $campo, string $message): Resultado
    {
        return new Resultado($tipo, $message, ['importadas' => 0, 'erros' => [$campo => $message]]);
    }
}
