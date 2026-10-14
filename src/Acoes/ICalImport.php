<?php

declare(strict_types=1);

require_once __DIR__ . '/Resultado.php';
require_once __DIR__ . '/Canal.php';

/**
 * Cadastro + primeira importação de um feed iCal (web POST /imoveis/{hash}/ical/importar e
 * API POST /api/imoveis/{hash}/ical/importar).
 *
 * Desfechos (tipo): vazio | limit | duplicate | title | ok | nok. dados: importadas (int).
 * A mensagem é o texto da web; a API traduz os tipos de recusa para os textos dela.
 *
 * Diferenças web/API preservadas de propósito (SNAPSHOT, ver tests/standalone/char_calendario_test.php),
 * escolhidas pelo canal ($canal):
 * - URL vazia: web devolve 'vazio' com "link salvo" (sem addFeed); API recusa antes de chamar a ação (422).
 * - CALENDAR_FEED_ADD: só a web grava, e sempre (inclusive recusas e URL vazia), depois do CALENDAR_SYNC.
 * - CALENDAR_SYNC: a web grava só em sucesso; a API grava em sucesso e em falha (resultado ok/nok).
 * O corpo .ics enviado pelo cliente nunca é usado: só o que o servidor baixa (exported_calendar_body/fetch).
 */
final class ICalImport
{
    public static function run(array $property, int $userId, string $url, string $feedTitle, Canal $canal): Resultado
    {
        $web = $canal === Canal::Web;
        $propertyId = (int) $property['id'];
        $events = [];
        $error = null;
        $tipo = 'vazio';
        $message = t('cal.link_saved');
        $count = 0;
        if ($url !== '') {
            $error = HouseRepository::addFeed($propertyId, $userId, $url, $feedTitle);
            if ($error === 'limit') {
                $tipo = 'limit';
                $message = t('cal.feed_limit');
            } elseif ($error === 'duplicate') {
                $tipo = 'duplicate';
                $message = t('cal.feed_duplicate');
            } elseif ($error === 'title') {
                $tipo = 'title';
                $message = t('cal.feed_title_required');
            } else {
                $body = exported_calendar_body($url) ?? CalendarSync::fetch($url);
                $ok = is_string($body) && $body !== '';
                // SNAPSHOT: a web toma o horário do last_synced_at ANTES do import_ics; a API, depois.
                $now = $web ? date('Y-m-d H:i:s') : null;
                if ($ok) {
                    $count = import_ics($propertyId, $body);
                }
                $now ??= date('Y-m-d H:i:s');
                foreach (HouseRepository::feedsWithUrl($propertyId) as $feed) {
                    if ($feed['url'] === $url) {
                        HouseRepository::markFeed((int) $feed['id'], $ok ? 'OK' : 'NOK', $now);
                        break;
                    }
                }
                $tipo = $ok ? 'ok' : 'nok';
                $message = $ok ? t('cal.imported') . ' ' . $count . ' ' . t('cal.blocked_dates') : t('cal.import_fail');
                if ($ok || !$web) {
                    $events[] = [ActivityLog::CALENDAR_SYNC, [
                        'imovel_id' => $propertyId,
                        'url' => CalendarSync::logRef($url),
                        'novas' => $count,
                        'resultado' => $ok ? 'ok' : 'nok',
                    ]];
                }
            }
        }
        if ($web) {
            $events[] = [
                ActivityLog::CALENDAR_FEED_ADD,
                ['imovel_id' => $propertyId, 'titulo' => $feedTitle, 'url' => CalendarSync::logRef($url), 'resultado' => $message],
                null,
                is_array($error) ? ['feed' => CalendarSync::logFeed($error['feed']), 'link' => CalendarSync::logRef($error['link'][0]['new']['external_ical_url'] ?? null)] : null,
            ];
        }
        return new Resultado($tipo, $message, ['importadas' => $count], $events);
    }
}
