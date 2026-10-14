<?php
// Divisão de src/helpers.php por tema: toda função definida em helpers.php no develop (bc1afa5) continua definida
// após require de helpers.php, e cada uma exatamente uma vez entre helpers.php e src/helpers/*.php.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/helpers.php';

$antes = [
    'app_url',
    'base_url',
    'request_base',
    'e',
    'redirect',
    'safe_search_return',
    'password_is_weak',
    'password_make',
    'expire_user_session',
    'account_previous',
    'password_phrase',
    'password_error',
    'phone_digits',
    'phone_is_valid',
    'compose_address',
    'city_label_for_cep',
    'address_from_input',
    'ini_bytes',
    'reject_oversize_upload',
    'raw_body',
    'ensure_session',
    'csrf_token',
    'csrf_check',
    'current_user',
    'require_user',
    'summary_from',
    'word_count',
    'search_filters_from_query',
    'property_ref',
    'cities',
    'haversine_km',
    'public_coord',
    'geo_salt',
    'public_distance_km',
    'parse_date',
    'date_pattern',
    'date_placeholder',
    'format_date',
    'month_label',
    'br_date',
    'format_time',
    'format_datetime',
    'nights_between',
    'calendar_months_cap',
    'property_calendar_months',
    'max_calendar_day',
    'money',
    'money_input',
    'money_brl',
    'parse_money',
    'view',
    'primary_photo',
    'search_query',
    'review_score',
    'stay_quote',
    'save_uploaded_photos',
    'ics_text',
    'export_ics',
    'exported_calendar_body',
    'import_ics',
    'expose_account_links',
    'page_data',
    'page_data_script',
    'photo_url',
    'photo_variant',
    'photo_srcset_attr',
    'csp_header',
];
t_eq(67, count($antes), 'lista do develop tem 67 funções');
foreach ($antes as $fn) {
    t_ok(function_exists($fn), $fn . ' continua definida');
}
$definidas = [];
$arquivos = [CL_ROOT . '/src/helpers.php'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CL_ROOT . '/src/helpers', FilesystemIterator::SKIP_DOTS)) as $info) {
    if ($info->isFile() && $info->getExtension() === 'php') {
        $arquivos[] = $info->getPathname();
    }
}
t_ok(count($arquivos) > 1, 'lista de arquivos de helpers não vazia (' . count($arquivos) . ')');
foreach ($arquivos as $arquivo) {
    preg_match_all('/^function (\w+)/m', (string) file_get_contents($arquivo), $m);
    $definidas = array_merge($definidas, $m[1]);
}
sort($definidas);
// Adicionadas depois da divisão, de propósito (a lista $antes continua sendo a do develop bc1afa5):
// asset_url saiu de src/AssetManifest.php para src/helpers/url.php (Sprint 3, Etapa 1; Revisor do #54).
$adicionadas = ['asset_url'];
$esperadas = array_merge($antes, $adicionadas);
sort($esperadas);
t_eq($esperadas, $definidas, 'mesmo conjunto de funções, sem duplicatas nem extras');
t_done();
