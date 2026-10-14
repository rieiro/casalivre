<?php

declare(strict_types=1);

/**
 * URL pública da aplicação, usada em links enviados ao usuário (ativação, redefinição, .ics).
 * Sempre vem de APP_URL (nunca do header Host, que o cliente controla).
 * Sem APP_URL: em APP_ENV=dev/local cai no Host da requisição; fora disso, falha com erro claro.
 */
function app_url(): string
{
    if (class_exists('Db')) {
        Db::loadEnv();
    }
    $env = trim((string) getenv('APP_URL'));
    if ($env !== '') {
        $parts = parse_url($env);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            throw new RuntimeException('APP_URL inválida: use esquema://host[:porta][/caminho], sem query.');
        }
        return rtrim($env, '/');
    }
    if (expose_account_links()) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
        return $scheme . '://' . $host;
    }
    throw new RuntimeException('APP_URL não configurada: obrigatória fora de APP_ENV=dev/local (links de e-mail e .ics).');
}

function base_url(): string
{
    return app_url();
}

function request_base(): string
{
    return app_url();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function safe_search_return(?string $value): string
{
    $value = str_replace(["\r", "\n"], '', (string) $value);
    if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//') || str_contains($value, '\\')) {
        return '/';
    }
    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || !in_array($path, ['/', '/busca'], true)) {
        return '/';
    }
    $query = parse_url($value, PHP_URL_QUERY);
    return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
}

require_once dirname(__DIR__) . '/conta/Auth/Password.php';
require_once dirname(__DIR__) . '/conta/Auth/Phone.php';
require_once dirname(__DIR__) . '/conta/Auth/Session.php';

function password_is_weak(string $password): bool
{
    return PasswordPolicy::isWeak($password);
}

function password_make(string $password): string
{
    return PasswordPolicy::hash($password);
}

function expire_user_session(): void
{
    $minutes = max(1, Settings::int('session_minutes') ?: 60);
    SessionGuard::expire($minutes);
}

function account_previous(array $user): array
{
    return [
        'nome' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'telefone' => (string) ($user['phone'] ?? ''),
        'cep' => (string) ($user['cep'] ?? ''),
        'endereco' => (string) ($user['address'] ?? ''),
        'estado' => (string) ($user['status'] ?? 'active'),
    ];
}

function password_phrase(string $key): string
{
    return sprintf(t($key), max(1, PasswordPolicy::$minLength));
}

function password_error(string $password, ?string $confirm = null): ?string
{
    $problem = PasswordPolicy::problem($password, $confirm);
    return match ($problem) {
        'short' => password_phrase('err.password_short'),
        'mismatch' => t('err.password_mismatch'),
        'weak' => password_phrase('err.password_weak'),
        default => null,
    };
}

function phone_digits(string $raw): string
{
    return PhoneNumber::digits($raw);
}

function phone_is_valid(string $raw): bool
{
    return PhoneNumber::isValid($raw);
}

function compose_address(string $street, string $number, string $complement): string
{
    $parts = [];
    foreach ([$street, $number, $complement] as $part) {
        $part = trim($part);
        if ($part !== '') {
            $parts[] = $part;
        }
    }
    return implode(', ', $parts);
}

function city_label_for_cep(string $cep): string
{
    $digits = str_pad(preg_replace('/\D/', '', $cep) ?? '', 8, '0', STR_PAD_LEFT);
    if (strlen($digits) !== 8) {
        return '';
    }
    $row = CepRepository::find($digits);
    if (!$row || trim((string) $row['city']) === '') {
        return '';
    }
    $known = CityRepository::match($row['city'] . ', ' . $row['uf']);
    return (string) ($known['label'] ?? ($row['city'] . ', ' . $row['uf']));
}

function address_from_input(array $input): ?array
{
    $cep = str_pad(preg_replace('/\D/', '', (string) ($input['cep'] ?? '')) ?? '', 8, '0', STR_PAD_LEFT);
    if (strlen($cep) !== 8) {
        return null;
    }
    $row = CepRepository::find($cep);
    if (!$row || trim((string) $row['street']) === '') {
        return null;
    }
    $number = trim((string) ($input['address_number'] ?? ''));
    if ($number === '') {
        return null;
    }
    $complement = trim((string) ($input['address_complement'] ?? ''));
    $street = trim((string) $row['street']);
    return [
        'cep' => $cep,
        'street' => $street,
        'address_number' => $number,
        'address_complement' => $complement,
        'address' => compose_address($street, $number, $complement),
        'cep_city' => (string) $row['city'],
        'cep_uf' => (string) $row['uf'],
    ];
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    return match ($unit) {
        'g' => (int) ($number * 1073741824),
        'm' => (int) ($number * 1048576),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function reject_oversize_upload(): void
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = ini_bytes((string) ini_get('post_max_size'));
    if ($length <= 0 || $max <= 0 || $length <= $max) {
        return;
    }
    $sent = number_format($length / 1048576, 1, ',', '');
    $limit = (string) (int) floor($max / 1048576);
    http_response_code(413);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Envio grande demais</title><body style="font-family:sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem"><h1>Envio grande demais</h1><p>Este envio tem ' . $sent . ' MB e o limite do sistema é ' . $limit . ' MB. Escolha menos fotos ou arquivos menores.</p><p><a href="javascript:history.back()">Voltar</a></p></body></html>';
    exit;
}

function raw_body(): string
{
    require_once dirname(__DIR__) . '/seguranca/Csrf.php';
    return Csrf::rawBody();
}

/** Abre a sessão sob demanda (antes de qualquer saída). */
function ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        require_once __DIR__ . '/SecureSession.php';
        SecureSession::start();
    }
}

function csrf_token(): string
{
    ensure_session();
    require_once dirname(__DIR__) . '/seguranca/Csrf.php';
    return Csrf::token();
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Token de segurança inválido. Recarregue a página.');
    }
}

function current_user(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }
    return UserRepository::findActive((int) $id);
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/entrar');
    }
    return $user;
}

function summary_from(string $description, int $maxWords = 45): string
{
    $words = preg_split('/\s+/u', trim($description)) ?: [];
    $words = array_values(array_filter($words, static fn ($w) => $w !== ''));
    if (count($words) > $maxWords) {
        $words = array_slice($words, 0, $maxWords);
    }
    return implode(' ', $words);
}

function word_count(string $text): int
{
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    return count(array_filter($words, static fn ($w) => $w !== ''));
}

function property_ref(array $property): string
{
    return HouseRepository::ensurePublicHash($property);
}

function cities(): array
{
    return [
        'Florianópolis' => [-27.5954, -48.5480],
        'Rio de Janeiro' => [-22.9068, -43.1729],
        'São Paulo' => [-23.5505, -46.6333],
        'Salvador' => [-12.9777, -38.5016],
        'Gramado' => [-29.3746, -50.8764],
        'Búzios' => [-22.7469, -41.8817],
    ];
}

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

function parse_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return ($date && $date->format('Y-m-d') === $value) ? $value : null;
    }
    $pattern = current_locale() === 'en' ? '!m/d/Y' : '!d/m/Y';
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat($pattern, $value);
    return ($date && $date->format(current_locale() === 'en' ? 'm/d/Y' : 'd/m/Y') === $value) ? $date->format('Y-m-d') : null;
}

function date_pattern(): string
{
    return current_locale() === 'en' ? 'm/d/Y' : 'd/m/Y';
}

function date_placeholder(): string
{
    return current_locale() === 'en' ? 'mm/dd/yyyy' : 'dd/mm/aaaa';
}

function format_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $iso = parse_date(substr($value, 0, 10));
    if ($iso === null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match)) {
        $iso = $match[1];
    }
    if ($iso === null) {
        return $value;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
    return $date ? $date->format(date_pattern()) : $value;
}

function month_label(DateTimeInterface $month): string
{
    $names = [
        'pt-BR' => [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
    ];
    $locale = function_exists('current_locale') ? current_locale() : 'pt-BR';
    $list = $names[$locale] ?? $names['pt-BR'];
    return $list[(int) $month->format('n')] . ' / ' . $month->format('Y');
}

function br_date(?string $value): string
{
    return format_date($value);
}

function format_time(?string $value): string
{
    $value ??= '';
    if (!preg_match('/^(\d{2}):(\d{2})/', $value, $m)) {
        return $value;
    }
    if (current_locale() !== 'en') {
        return $m[1] . ':' . $m[2];
    }
    $hour = (int) $m[1];
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $hour12 = $hour % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }
    return $hour12 . ':' . $m[2] . ' ' . $suffix;
}

function format_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $date = format_date(substr($value, 0, 10));
    if (preg_match('/(\d{2}:\d{2})/', $value, $m)) {
        return trim($date . ' ' . format_time($m[1]));
    }
    return $date;
}

function nights_between(string $checkin, string $checkout): array
{
    $startIso = parse_date($checkin);
    $endIso = parse_date($checkout);
    if (!$startIso || !$endIso) {
        return [];
    }
    $start = new DateTimeImmutable($startIso);
    $end = new DateTimeImmutable($endIso);
    if ($end <= $start) {
        return [];
    }
    $days = [];
    for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
        $days[] = $cursor->format('Y-m-d');
    }
    return $days;
}

function calendar_months_cap(): int
{
    return max(1, Settings::int('calendar_months'));
}

function property_calendar_months(array $property): int
{
    $cap = calendar_months_cap();
    $own = (int) ($property['calendar_months'] ?? 0);
    if ($own < 1) {
        $own = 6;
    }
    return min($own, $cap);
}

function max_calendar_day(?array $property = null): string
{
    $months = $property ? property_calendar_months($property) : calendar_months_cap();
    return (new DateTimeImmutable('today'))->modify('+' . $months . ' months')->format('Y-m-d');
}

function money(int $amount): string
{
    if (current_locale() === 'en') {
        return '$' . number_format($amount, 0, '.', ',');
    }
    if (current_locale() === 'es') {
        return number_format($amount, 0, ',', '.') . ' €';
    }
    return 'R$ ' . number_format($amount, 0, ',', '.');
}

function money_input(int $amount): string
{
    return (string) $amount;
}

function money_brl(int $amount): string
{
    return 'R$ ' . number_format(max(0, $amount), 2, ',', '.');
}

function parse_money(string $raw): int
{
    $raw = trim(str_replace(['R$', '€', '$', "\u{00A0}", ' '], '', trim($raw)));
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?$/', $raw, $match)) {
        if (($match[2] ?? '') !== '' && (int) $match[2] !== 0) {
            return 0;
        }
        return (int) str_replace('.', '', $match[1]);
    }
    if (preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{1,2}))?$/', $raw, $match)) {
        if (($match[2] ?? '') !== '' && (int) $match[2] !== 0) {
            return 0;
        }
        return (int) str_replace(',', '', $match[1]);
    }
    return 0;
}

function view(string $template, array $data = []): void
{
    $data['user'] = $data['user'] ?? current_user();
    $data['csrf'] = csrf_token();
    $data['locale'] = current_locale();
    extract($data, EXTR_SKIP);
    ob_start();
    include dirname(__DIR__) . '/views/' . $template . '.php';
    $content = ob_get_clean();
    $layout = preg_replace('/[^a-z_]/', '', (string) ($data['layout'] ?? 'layout')) ?: 'layout';
    include dirname(__DIR__) . '/views/' . $layout . '.php';
}

function primary_photo(int $propertyId): ?string
{
    return PhotoRepository::primaryPath($propertyId);
}

function search_query(array $filters): string
{
    $query = $filters;
    $amenities = $query['amenidades'] ?? [];
    unset($query['amenidades']);
    if (is_array($amenities) && $amenities !== []) {
        $query['amenidade'] = array_values($amenities);
    }
    return http_build_query($query);
}

function review_score(int $propertyId): ?float
{
    return HouseRepository::averageScore($propertyId);
}

function stay_quote(array $property, array $nights, ?array $byDay = null): ?array
{
    if ($nights === []) {
        return null;
    }
    $byDay ??= HouseRepository::calendarForNights((int) $property['id'], $nights);
    $custom = (int) ($byDay[$nights[0]]['min_nights'] ?? 0);
    $minimum = $custom > 0 ? $custom : (int) $property['min_nights'];
    if (count($nights) < $minimum) {
        return null;
    }
    $max = max_calendar_day($property);
    $total = 0;
    foreach ($nights as $day) {
        if ($day > $max || !empty($byDay[$day]['blocked'])) {
            return null;
        }
        $total += (int) ($byDay[$day]['price'] ?? $property['nightly_price']);
    }
    $cleaning = max(0, (int) ($property['cleaning_fee'] ?? 0));
    return [
        'nights' => count($nights),
        'cleaning' => $cleaning,
        'total' => $total + $cleaning,
        'nightly' => (int) round($total / count($nights)),
    ];
}

function save_uploaded_photos(int $userId, int $propertyId, array $files, ?array &$created = null): int
{
    return PhotoStorage::storeUploads($userId, $propertyId, $files, $created);
}

function ics_text(string $value): string
{
    return str_replace(["\\", "\r\n", "\n", "\r", ';', ','], ['\\\\', '\\n', '\\n', '', '\\;', '\\,'], $value);
}

function export_ics(array $property): string
{
    $title = ics_text((string) ($property['title'] ?? 'CasaLivre'));
    $stamp = gmdate('Ymd\THis\Z');
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//CasaLivre//Calendario//PT',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . $title,
    ];
    $groups = [];
    foreach (HouseRepository::exportDays((int) ($property['id'] ?? 0)) as $row) {
        $origin = $row['origin'];
        if ($origin === '' && $row['blocked']) {
            $origin = 'sistema';
        }
        $summary = match ($origin) {
            'reserva' => 'Reservado',
            'sync' => 'Indisponivel',
            default => 'Bloqueado',
        };
        $last = $groups[count($groups) - 1] ?? null;
        $next = null;
        try {
            $next = $last ? (new DateTimeImmutable($last['end']))->modify('+1 day')->format('Y-m-d') : null;
        } catch (Exception) {
            $next = null;
        }
        if ($last && $last['summary'] === $summary && $next === $row['day']) {
            $groups[count($groups) - 1]['end'] = $row['day'];
            continue;
        }
        $groups[] = ['start' => $row['day'], 'end' => $row['day'], 'summary' => $summary];
    }
    foreach ($groups as $group) {
        try {
            $start = new DateTimeImmutable($group['start']);
            $end = (new DateTimeImmutable($group['end']))->modify('+1 day');
        } catch (Exception) {
            continue;
        }
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:casalivre-' . (int) ($property['id'] ?? 0) . '-' . $start->format('Ymd') . '-' . $end->format('Ymd') . '@casalivre.local';
        $lines[] = 'DTSTAMP:' . $stamp;
        $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
        $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
        $lines[] = 'SUMMARY:' . ics_text($group['summary']);
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}

function exported_calendar_body(string $url): ?string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if (!preg_match('#/ical/([a-f0-9]{32})$#', $path, $match)) {
        return null;
    }
    $source = HouseRepository::findByIcalHash($match[1]);
    if (!$source || (string) ($source['status'] ?? 'active') !== 'active') {
        return null;
    }
    return export_ics($source);
}

function import_ics(int $propertyId, string $body, ?array &$alreadyBlocked = null): int
{
    $body = preg_replace("/\r?\n[ \t]/", '', $body) ?? $body;
    $house = HouseRepository::find($propertyId);
    $max = max_calendar_day($house ?: null);
    HouseRepository::clearBlockedAfter($propertyId, $max);
    $today = date('Y-m-d');
    if ($alreadyBlocked === null) {
        $alreadyBlocked = [];
        foreach (HouseRepository::blockedDays($propertyId) as $row) {
            $alreadyBlocked[$row['day']] = (string) ($row['origin'] ?? '');
        }
    }
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $body, $events)) {
        return 0;
    }
    $count = 0;
    foreach ($events[1] as $event) {
        if (preg_match('/UID:casalivre-' . $propertyId . '-/', $event)) {
            continue;
        }
        if (!preg_match('/DTSTART(?:;[^:\r\n]*)?:(\d{8})/', $event, $startMatch)) {
            continue;
        }
        $start = DateTimeImmutable::createFromFormat('!Ymd', $startMatch[1]);
        if (!$start) {
            continue;
        }
        $end = $start->modify('+1 day');
        if (preg_match('/DTEND(?:;[^:\r\n]*)?:(\d{8})/', $event, $endMatch)) {
            $parsedEnd = DateTimeImmutable::createFromFormat('!Ymd', $endMatch[1]);
            if ($parsedEnd && $parsedEnd > $start) {
                $end = $parsedEnd;
            }
        }
        $summary = '';
        if (preg_match('/SUMMARY:(.*)/', $event, $summaryMatch)) {
            $summary = strtolower(trim($summaryMatch[1]));
        }
        $origin = 'reserva';
        if (preg_match('/bloquead|indispon|ocupado|closed|not available|unavailable|blocked/', $summary)) {
            $origin = 'sync';
        }
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $iso = $day->format('Y-m-d');
            if ($iso > $max || $iso < $today) {
                continue;
            }
            $current = $alreadyBlocked[$iso] ?? null;
            if ($current === 'sync' || $current === 'reserva') {
                continue;
            }
            HouseRepository::blockDay($propertyId, $iso, $origin);
            $alreadyBlocked[$iso] = $origin;
            $count++;
        }
    }
    return $count;
}

/**
 * Links de ativação/redefinição só podem aparecer em respostas (API ou tela) em ambiente de
 * desenvolvimento: APP_ENV=dev ou local. Sem APP_ENV (ou qualquer outro valor) => não expõe.
 */
function expose_account_links(): bool
{
    if (class_exists('Db')) {
        Db::loadEnv();
    }
    return in_array(strtolower(trim((string) getenv('APP_ENV'))), ['dev', 'local'], true);
}

/**
 * Dados do servidor para os scripts da página (CSP sem script inline): bloco JSON não executável,
 * lido por CL.data() em /assets/safe-dom.js.
 */
function page_data(array $data = []): array
{
    static $registry = [];
    $registry = array_merge($registry, $data);
    return $registry;
}

/**
 * Bloco JSON único (<script type="application/json" id="page-data">) emitido no <head> pelo layout:
 * dados registrados pela view + csrf (fonte única do token para CL.api) + textos de erro do CL.api.
 */
function page_data_script(string $csrf): string
{
    $data = page_data() + [
        'csrf' => $csrf,
        'i18n' => ['netError' => t('js.net_error'), 'requestFailed' => t('js.request_failed')],
    ];
    return '<script type="application/json" id="page-data">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';
}

/** Caminho de foto seguro para src=: só relativo em /uploads/ (sem "..", esquema ou host); senão o placeholder. */
function photo_url(?string $path): string
{
    $path = (string) $path;
    if (preg_match('#^/uploads/[A-Za-z0-9_/.-]+$#', $path) === 1 && !str_contains($path, '..')) {
        return $path;
    }
    return '/assets/placeholder.svg';
}

/**
 * Variante WebP de uma foto pronta (/uploads/fotos/<uuid>-1920.webp): thumb 320, card 800, full 1920.
 * Caminhos antigos (sem variantes) voltam como estão.
 */
function photo_variant(?string $path, string $size): string
{
    $path = (string) $path;
    $w = ImageWorker::SIZES[$size] ?? null;
    if ($w === null || preg_match('#^(/uploads/fotos/[0-9a-f-]{36})-\d+\.webp$#', $path, $m) !== 1) {
        return $path;
    }
    return $m[1] . '-' . $w . '.webp';
}

/** Atributos srcset/sizes (já escapados, com espaço à frente) para fotos com variantes; '' nas antigas. */
function photo_srcset_attr(?string $path, string $sizes): string
{
    $path = (string) $path;
    if (preg_match('#^/uploads/fotos/[0-9a-f-]{36}-\d+\.webp$#', $path) !== 1) {
        return '';
    }
    $set = [];
    foreach (ImageWorker::SIZES as $name => $w) {
        $set[] = photo_variant($path, $name) . ' ' . $w . 'w';
    }
    return ' srcset="' . e(implode(', ', $set)) . '" sizes="' . e($sizes) . '"';
}

/**
 * Único ponto que monta o valor do header Content-Security-Policy. Sempre estrita: sem inline/eval em
 * script, sem 'unsafe-inline' em style, sem data:/blob:; só os tiles do OpenStreetMap como imagem externa.
 */
function csp_header(): string
{
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self'",
        "img-src 'self' https://tile.openstreetmap.org",
        "object-src 'none'",
        "base-uri 'none'",
        "frame-ancestors 'self'",
        "form-action 'self'",
    ]);
}
