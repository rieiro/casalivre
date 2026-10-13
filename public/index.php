<?php

declare(strict_types=1);

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Settings.php';
require __DIR__ . '/../src/I18n.php';
require __DIR__ . '/../src/CityRepository.php';
require __DIR__ . '/../src/CepRepository.php';
require __DIR__ . '/../src/UserRepository.php';
PasswordPolicy::$minLength = max(1, Settings::int('password_min_length') ?: 8);
AccountFlow::$sendLink = static function (string $email, string $link): void {
};
require __DIR__ . '/../src/HouseRepository.php';
require __DIR__ . '/../src/PhotoRepository.php';
require __DIR__ . '/../src/PhotoStorage.php';
require __DIR__ . '/../src/helpers.php';
reject_oversize_upload();
require __DIR__ . '/../src/HouseService.php';
require_once __DIR__ . '/../src/Amenities.php';
require __DIR__ . '/../src/CalendarSync.php';
require __DIR__ . '/../src/Api.php';
require __DIR__ . '/../src/ActivityLog.php';
require __DIR__ . '/../src/SecureSession.php';
require __DIR__ . '/../src/ExceptionShield.php';

// Stack traces sem valores de argumentos (evita vazar tokens/senhas em logs de erro).
ini_set('zend.exception_ignore_args', '1');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

SecureSession::start();
// Log por requisição da API iniciado ANTES do pipeline de segurança, para que as
// respostas 401/419/429 geradas pelos middlewares (que encerram com exit) também
// sejam registradas pelo shutdown hook do RequestLog.
if (str_starts_with($path, '/api')) {
    RequestLog::start($path, $method, api_body());
}
expire_user_session();
ExceptionShield::register();

// CORS para frontend em outro servidor (configurar CORS_ORIGIN no .env)
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$allowed = getenv('CORS_ORIGIN') ?: '';
if ($origin !== '' && ($allowed === '*' || $allowed === $origin || ($allowed !== '' && str_contains($allowed, $origin)))) {
    header('Access-Control-Allow-Origin: ' . ($allowed === '*' ? '*' : $origin));
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

require __DIR__ . '/../src/LoginRateLimiter.php';
require __DIR__ . '/../seguranca/Seguranca.php';

return Seguranca::pipeline(
    [
        'campos_puros' => ['password', 'password_confirm', '_csrf', 'external_ical_url'],
        'sem_csrf' => [],
        'login' => [
            ['POST', '/entrar'],
            ['POST', '/api/sessao'],
        ],
        'bloqueado' => static fn (): bool => LoginRateLimiter::blocked(),
        'privado' => static fn (string $path, string $method): bool => casa_privado($path, $method),
        'recusar' => static function (string $code, string $path): never {
            casa_recusar($code, $path);
        },
    ],
    ['path' => $path, 'method' => $method],
    static function (array $request): mixed {
        return dispatch((string) $request['path'], (string) $request['method']);
    }
);

function casa_privado(string $path, string $method): bool
{
    if (in_array($path, ['/painel', '/conta', '/conta/excluir', '/imoveis/novo', '/sair'], true)) {
        return true;
    }
    if ($path === '/imoveis' && $method === 'POST') {
        return true;
    }
    if (preg_match('#^/imoveis/[a-f0-9]{16}$#', $path) === 1 && $method !== 'GET') {
        return true;
    }
    if (preg_match('#^/imoveis/[a-f0-9]{16}/(editar|fotos|calendario|ical|excluir)(/|$)#', $path) === 1) {
        return true;
    }
    if (str_starts_with($path, '/api/users/eu')) {
        return true;
    }
    if ($path === '/api/casas' && $method === 'POST') {
        return true;
    }
    return preg_match('#^/api/casas/[a-f0-9]{16}#', $path) === 1 && $method !== 'GET';
}

/**
 * Envia a resposta de recusa dos middlewares de segurança e ENCERRA a requisição.
 *
 * Todos os casos terminam com exit (por isso o tipo de retorno é never). O dispatch
 * nunca roda depois de uma recusa. O log da API continua sendo gravado, porque o
 * RequestLog usa register_shutdown_function, que roda mesmo após exit.
 *
 * - 'csrf'       -> 419 (JSON em /api, HTML fora) + exit
 * - 'rate_limit' -> 429 + Retry-After (enviado pelo RateLimit) (JSON em /api, tela de login fora) + exit
 * - 'login'      -> 302 para /entrar + exit
 * - outros       -> 401 JSON + exit
 */
function casa_recusar(string $code, string $path): never
{
    $api = str_starts_with($path, '/api');
    if ($code === 'csrf') {
        http_response_code(419);
        if ($api) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Token de segurança inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Pedido recusado</title><body style="font-family:sans-serif;max-width:36rem;margin:3rem auto"><h1>Pedido recusado</h1><p>O token de segurança não confere. Recarregue a página e tente de novo.</p><p><a href="/">Voltar ao início</a></p></body></html>';
        exit;
    }
    if ($code === 'rate_limit') {
        http_response_code(429);
        $message = LoginRateLimiter::message();
        if ($api) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        view('login', ['error' => $message]);
        exit;
    }
    if ($code === 'login') {
        header('Location: /entrar');
        exit;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function dispatch(string $path, string $method): mixed
{
if ($path === '/idioma' && $method === 'POST') {
    csrf_check();
    $locale = (string) ($_POST['locale'] ?? '');
    if (isset(I18n::LOCALES[$locale])) {
        $_SESSION['locale'] = $locale;
        activity(ActivityLog::LOCALE, ['idioma' => $locale]);
    }
    $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $parts = parse_url($back);
    $sameHost = isset($parts['host']) && $parts['host'] === ($_SERVER['HTTP_HOST'] ?? '');
    $target = $sameHost ? (($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '')) : '/';
    redirect($target);
}

if ($path === '/cidades' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(CityRepository::suggest((string) ($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
    return null;
}

if ($path === '/ceps' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $row = CepRepository::find((string) ($_GET['q'] ?? ''));
    if (!$row || trim((string) $row['street']) === '') {
        echo json_encode(['error' => 'CEP não encontrado ou sem rua cadastrada.'], JSON_UNESCAPED_UNICODE);
        return null;
    }
    $known = CityRepository::match($row['city'] . ', ' . $row['uf']);
    echo json_encode([
        'cep' => $row['cep'],
        'street' => $row['street'],
        'neighborhood' => $row['neighborhood'],
        'city' => $row['city'],
        'uf' => $row['uf'],
        'city_label' => $known['label'] ?? ($row['city'] . ', ' . $row['uf']),
    ], JSON_UNESCAPED_UNICODE);
    return null;
}

if ($path === '/verificar' && $method === 'GET') {
    $result = AccountFlow::activate((string) ($_GET['token'] ?? ''));
    if ($result['ok']) {
        activity(ActivityLog::EMAIL_VERIFY, ['email' => $result['email'], 'resultado' => 'ok']);
        view('message', ['title' => t('auth.verify_ok'), 'message' => t('auth.back_login')]);
        return null;
    }
    activity(ActivityLog::EMAIL_VERIFY, ['resultado' => 'invalido']);
    view('message', ['title' => t('auth.verify_bad'), 'message' => '']);
    return null;
}

if ($path === '/senha/esqueci' && $method === 'GET') {
    view('password_forgot', ['error' => null, 'link' => null, 'expires' => null]);
    return null;
}

if ($path === '/senha/esqueci' && $method === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $sent = AccountFlow::askReset($email, Settings::int('password_reset_minutes'), request_base() . '/senha/redefinir?token=');
    if ($sent) {
        activity(ActivityLog::PASSWORD_RESET_ASK, ['email' => $email]);
    }
    view('password_forgot', ['error' => null, 'link' => $sent['link'] ?? null, 'expires' => $sent['expires'] ?? null, 'notice' => t('auth.reset_missing')]);
    return null;
}

if ($path === '/senha/redefinir' && $method === 'GET') {
    view('password_reset', ['error' => null, 'token' => (string) ($_GET['token'] ?? '')]);
    return null;
}

if ($path === '/senha/redefinir' && $method === 'POST') {
    csrf_check();
    $token = (string) ($_POST['token'] ?? '');
    $result = AccountFlow::reset($token, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirm'] ?? ''));
    if ($result['error']) {
        view('password_reset', ['error' => account_text($result['error']), 'token' => $token]);
        return null;
    }
    activity(ActivityLog::PASSWORD_RESET, ['email' => $result['email']], ['senha' => 'definida'], ['senha' => 'alterada']);
    view('message', ['title' => t('auth.reset_save'), 'message' => t('auth.verify_ok')]);
    return null;
}

if (str_starts_with($path, '/api')) {
    handle_api($path, $method);
    return null;
}

if ($path === '/' && $method === 'GET') {
    $hasSearch = trim((string) ($_GET['localidade'] ?? '')) !== ''
        || (string) ($_GET['checkin'] ?? '') !== ''
        || (string) ($_GET['checkout'] ?? '') !== '';
    if ($hasSearch) {
        redirect('/busca' . ((string) ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . (string) $_SERVER['QUERY_STRING'] : ''));
    }
    view('home', ['filters' => [
        'localidade' => '',
        'checkin' => '',
        'checkout' => '',
        'hospedes' => 0,
    ]]);
    return null;
}

if ($path === '/busca/mapa' && $method === 'GET') {
    $filters = [
        'localidade' => trim((string) ($_GET['localidade'] ?? '')),
        'checkin' => (string) ($_GET['checkin'] ?? ''),
        'checkout' => (string) ($_GET['checkout'] ?? ''),
        'hospedes' => (int) ($_GET['hospedes'] ?? 0),
        'raio' => (int) ($_GET['raio'] ?? 0),
        'preco_min' => (int) ($_GET['preco_min'] ?? 0),
        'preco_max' => (int) ($_GET['preco_max'] ?? 0),
        'score_min' => (float) ($_GET['score_min'] ?? 0),
        'amenidades' => array_values(array_filter((array) ($_GET['amenidade'] ?? []), 'is_string')),
    ];
    view('map', [
        'filters' => $filters,
        'results' => [],
        'searched' => $filters['localidade'] !== '' || $filters['checkin'] !== '' || $filters['checkout'] !== '',
        'maps_key' => maps_key(),
        'use_api' => true,
    ]);
    return null;
}

if ($path === '/busca' && $method === 'GET') {
    // Resultados vêm da API (/api/busca). A view só monta o formulário e carrega via JS.
    $filters = [
        'localidade' => trim((string) ($_GET['localidade'] ?? '')),
        'checkin' => (string) ($_GET['checkin'] ?? ''),
        'checkout' => (string) ($_GET['checkout'] ?? ''),
        'hospedes' => (int) ($_GET['hospedes'] ?? 0),
        'raio' => (int) ($_GET['raio'] ?? 0),
        'preco_min' => (int) ($_GET['preco_min'] ?? 0),
        'preco_max' => (int) ($_GET['preco_max'] ?? 0),
        'score_min' => (float) ($_GET['score_min'] ?? 0),
        'amenidades' => array_values(array_filter((array) ($_GET['amenidade'] ?? []), 'is_string')),
    ];
    $searched = $filters['localidade'] !== '' || $filters['checkin'] !== '' || $filters['checkout'] !== '';
    view('search', [
        'filters' => $filters,
        'results' => [],
        'searched' => $searched,
        'facets' => [],
        'use_api' => true,
    ]);
    return null;
}

if ($path === '/busca-novo' && $method === 'GET') {
    $filters = [
        'localidade' => trim((string) ($_GET['localidade'] ?? '')),
        'checkin' => (string) ($_GET['checkin'] ?? ''),
        'checkout' => (string) ($_GET['checkout'] ?? ''),
        'hospedes' => (int) ($_GET['hospedes'] ?? 0),
        'raio' => (int) ($_GET['raio'] ?? 0),
        'preco_min' => (int) ($_GET['preco_min'] ?? 0),
        'preco_max' => (int) ($_GET['preco_max'] ?? 0),
        'score_min' => (float) ($_GET['score_min'] ?? 0),
        'amenidades' => array_values(array_filter((array) ($_GET['amenidade'] ?? []), 'is_string')),
    ];
    view('search_studio', [
        'filters' => $filters,
        'results' => [],
        'searched' => $filters['localidade'] !== '' || $filters['checkin'] !== '' || $filters['checkout'] !== '',
        'facets' => [],
        'layout' => 'layout_studio',
        'use_api' => true,
    ]);
    return null;
}

if ($path === '/cadastro' && $method === 'GET') {
    view('register', ['error' => null]);
    return null;
}

if ($path === '/cadastro' && $method === 'POST') {
    csrf_check();
    $registerOld = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'cpf' => trim((string) ($_POST['cpf'] ?? '')),
        'cep' => trim((string) ($_POST['cep'] ?? '')),
        'address_number' => trim((string) ($_POST['address_number'] ?? '')),
        'address_complement' => trim((string) ($_POST['address_complement'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
    ];
    $result = AccountFlow::register($_POST, Settings::int('email_verify_minutes'), request_base() . '/verificar?token=');
    if ($result['error']) {
        view('register', ['error' => account_text($result['error']), 'old' => $registerOld]);
        return null;
    }
    activity(ActivityLog::REGISTER, ['email' => $result['email']]);
    view('verify_pending', ['link' => $result['link'], 'expires' => $result['expires']]);
    return null;
}

if ($path === '/entrar' && $method === 'GET') {
    $error = null;
    if (!empty($_SESSION['session_expired'])) {
        $error = t('auth.session_expired');
        unset($_SESSION['session_expired']);
    }
    view('login', ['error' => $error]);
    return null;
}

if ($path === '/entrar' && $method === 'POST') {
    csrf_check();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $result = AccountFlow::checkLogin($email, (string) ($_POST['password'] ?? ''), Settings::int('login_max_failures'));
    if ($result['status'] === 'locked') {
        if (isset($result['failures'])) {
            LoginRateLimiter::hit();
        }
        $params = ['email' => $email];
        if (isset($result['failures'])) {
            $params['tentativas'] = $result['failures'];
        }
        activity(ActivityLog::LOGIN_LOCKED, $params);
        view('login', ['error' => t('err.locked')]);
        return null;
    }
    if ($result['status'] === 'bad') {
        $ipFailures = LoginRateLimiter::hit();
        activity(ActivityLog::LOGIN_FAIL, ['email' => $email]);
        if ($ipFailures >= LoginRateLimiter::maxAttempts()) {
            http_response_code(429);
            view('login', ['error' => LoginRateLimiter::message()]);
            return null;
        }
        view('login', ['error' => t('err.bad_login')]);
        return null;
    }
    if ($result['status'] === 'deleted' || $result['status'] === 'inactive') {
        activity(ActivityLog::LOGIN_FAIL, ['email' => $email, 'motivo' => $result['status'] === 'deleted' ? 'apagado' : 'inactive']);
        view('login', ['error' => account_text($result['status'])]);
        return null;
    }
    if ($result['status'] === 'pending') {
        $pending = AccountFlow::ensureActivation($result['user'], Settings::int('email_verify_minutes'), request_base() . '/verificar?token=');
        view('verify_pending', ['link' => $pending['link'], 'expires' => $pending['expires']]);
        return null;
    }
    AccountFlow::openSession((int) $result['user']['id']);
    LoginRateLimiter::clear();
    activity(ActivityLog::LOGIN_OK, ['email' => $email]);
    redirect('/painel');
}

if ($path === '/sair' && $method === 'POST') {
    csrf_check();
    activity(ActivityLog::LOGOUT);
    unset($_SESSION['user_id']);
    redirect('/');
}

if ($path === '/conta' && $method === 'GET') {
    view('account', ['error' => null, 'user' => require_user()]);
    return null;
}

if ($path === '/conta' && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $error = user_input_error($_POST, false);
    if ($error) {
        view('account', ['error' => $error, 'user' => $user]);
        return null;
    }
    $fields = user_fields($_POST, $user);
    try {
        $changed = UserRepository::update((int) $user['id'], $fields);
        $pair = $changed[0] ?? null;
        if ($pair) {
            activity(ActivityLog::ACCOUNT_UPDATE, ['email' => $fields['email']], $pair['old'], $pair['new']);
        }
    } catch (PDOException) {
        view('account', ['error' => t('err.email_taken'), 'user' => $user]);
        return null;
    }
    redirect('/painel');
}

if ($path === '/conta/excluir' && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $changed = UserRepository::deactivate((int) $user['id'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $pair = $changed[0] ?? null;
    if ($pair) {
        activity(ActivityLog::ACCOUNT_DELETE, ['email' => $user['email'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''], $pair['old'], $pair['new']);
    }
    unset($_SESSION['user_id']);
    redirect('/');
}

if ($path === '/painel' && $method === 'GET') {
    require_user();
    view('dashboard', ['properties' => [], 'use_api' => true]);
    return null;
}

if ($path === '/imoveis/novo' && $method === 'GET') {
    require_user();
    view('property_form', ['error' => null, 'property' => null, 'cities' => cities()]);
    return null;
}

if ($path === '/imoveis' && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $created = HouseService::create((int) $user['id'], $_POST);
    if (isset($created['error'])) {
        view('property_form', ['error' => $created['error'], 'property' => null, 'posted' => $_POST, 'cities' => cities()]);
        return null;
    }
    activity(ActivityLog::PROPERTY_CREATE, ['imovel_id' => (int) $created['id'], 'titulo' => (string) ($_POST['title'] ?? '')], null, $created['atual'] ?? null);
    redirect('/imoveis/' . property_ref(HouseRepository::find((int) $created['id'])) . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})$#', $path, $m) && $method === 'GET') {
    view('property', [
        'hash' => $m[1],
        'use_api' => true,
        'checkin' => (string) ($_GET['checkin'] ?? ''),
        'checkout' => (string) ($_GET['checkout'] ?? ''),
    ]);
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $updated = HouseService::update((int) $user['id'], house_id($m[1]), $_POST);
    if (isset($updated['error'])) {
        $property = HouseRepository::findByUser((int) $user['id'], house_id($m[1]));
        view('property_form', [
            'error' => $updated['error'],
            'property' => $property,
            'posted' => $_POST,
            'cities' => cities(),
            'photos' => $property ? PhotoRepository::list((int) $property['id']) : [],
        ]);
        return null;
    }
    activity(ActivityLog::PROPERTY_UPDATE, ['imovel_id' => house_id($m[1]), 'titulo' => (string) ($_POST['title'] ?? ''), 'cidade' => (string) ($_POST['city'] ?? '')] + HouseService::amenityChange($updated['anterior'] ?? [], $updated['atual'] ?? []), $updated['anterior'] ?? null, $updated['atual'] ?? null);
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/excluir$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    HouseService::remove((int) $user['id'], house_id($m[1]));
    redirect('/painel');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/editar$#', $path, $m) && $method === 'GET') {
    $user = require_user();
    $property = property_for_user((int) $user['id'], house_id($m[1]));
    view('property_form', [
        'error' => null,
        'upload_notice' => $_SESSION['upload_notice'] ?? null,
        'property' => $property,
        'cities' => cities(),
        'photos' => PhotoRepository::list((int) $property['id']),
    ]);
    unset($_SESSION['upload_notice']);
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    property_for_user((int) $user['id'], house_id($m[1]));
    $createdPhotos = [];
    $saved = save_uploaded_photos((int) $user['id'], house_id($m[1]), $_FILES['fotos'] ?? [], $createdPhotos);
    $sent = is_array($_FILES['fotos']['name'] ?? null) ? count(array_filter($_FILES['fotos']['name'], static fn ($name) => $name !== '')) : 0;
    if ($sent === 0) {
        $_SESSION['upload_notice'] = 'Nenhuma foto chegou. O envio é maior do que o limite do sistema.';
    } elseif ($saved < $sent) {
        $limit = Settings::int('max_photos_per_property') ?: 40;
        $_SESSION['upload_notice'] = "Salvas $saved de $sent fotos. Cada imóvel pode ter até $limit fotos, e cada arquivo até 16 MB (jpg, png, webp ou gif).";
    }
    activity(ActivityLog::PHOTO_ADD, ['imovel_id' => house_id($m[1]), 'enviadas' => $sent, 'salvas' => $saved], null, ['fotos' => $createdPhotos]);
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/excluir$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    property_for_user((int) $user['id'], house_id($m[1]));
    $removed = PhotoRepository::deleteIds(house_id($m[1]), array_values(array_unique(array_map('intval', (array) ($_POST['fotos'] ?? [])))));
    foreach ($removed['old'] as $photo) {
        PhotoStorage::deletePublicFile((string) ($photo['path'] ?? ''));
    }
    if ($removed['old'] !== []) {
        activity(ActivityLog::PHOTO_DELETE, ['imovel_id' => house_id($m[1]), 'quantidade' => count($removed['old'])], ['fotos' => $removed['old']], ['estado' => 'removida']);
    }
    if ($removed['capa'] !== []) {
        activity(ActivityLog::PHOTO_COVER, ['imovel_id' => house_id($m[1])], $removed['capa'][0]['old'] ?? null, $removed['capa'][0]['new'] ?? null);
    }
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/(\d+)/excluir$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    property_for_user((int) $user['id'], house_id($m[1]));
    $removed = PhotoRepository::delete(house_id($m[1]), (int) $m[2]);
    if ($removed['old']) {
        PhotoStorage::deletePublicFile((string) $removed['old']['path']);
        activity(ActivityLog::PHOTO_DELETE, ['imovel_id' => house_id($m[1]), 'foto_id' => (int) $removed['old']['id']], $removed['old'], ['estado' => 'removida']);
    }
    if ($removed['capa'] !== []) {
        activity(ActivityLog::PHOTO_COVER, ['imovel_id' => house_id($m[1])], $removed['capa'][0]['old'] ?? null, $removed['capa'][0]['new'] ?? null);
    }
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'GET') {
    $user = require_user();
    $property = property_for_user((int) $user['id'], house_id($m[1]));
    $removedDays = HouseRepository::clearBlockedAfter((int) $property['id'], max_calendar_day($property));
    if ($removedDays !== []) {
        $audit = HouseRepository::nightAudit($removedDays);
        activity(ActivityLog::CALENDAR_UPDATE, ['imovel_id' => (int) $property['id'], 'motivo' => 'limite'], $audit['anterior'], ['estado' => 'removida']);
    }
    view('calendar', [
        'property' => $property,
        'days' => HouseRepository::calendarDays((int) $property['id']),
        'feeds' => HouseRepository::feeds((int) $property['id']),
        'message' => null,
    ]);
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $property = property_for_user((int) $user['id'], house_id($m[1]));
    $saved = save_calendar($property, $_POST);
    $message = $saved['message'];
    $action = (string) ($_POST['acao'] ?? '');
    $params = [
        'imovel_id' => (int) $property['id'],
        'inicio' => (string) ($_POST['inicio'] ?? ''),
        'fim' => (string) ($_POST['fim'] ?? ''),
        'resultado' => $message,
    ];
    foreach ($saved['logs'] as $log) {
        activity($log['code'], $params + $log['extra'], $log['anterior'], $log['atual']);
    }
    $viewName = (string) ($_POST['cal_view'] ?? 'day');
    if (!in_array($viewName, ['day', 'price', 'min'], true)) {
        $viewName = 'day';
    }
    view('calendar', [
        'property' => HouseRepository::find((int) $property['id']),
        'days' => HouseRepository::calendarDays((int) $property['id']),
        'feeds' => HouseRepository::feeds((int) $property['id']),
        'message' => $message,
        'restore' => $action === 'salvar' ? [
            'inicio' => (string) ($_POST['inicio'] ?? ''),
            'fim' => (string) ($_POST['fim'] ?? ''),
            'view' => $viewName,
        ] : null,
    ]);
    return null;
}

if (preg_match('#^/ical/([a-f0-9]{32})$#', $path, $m) && $method === 'GET') {
    $property = HouseRepository::findByIcalHash($m[1]);
    if (!$property || ($property['status'] ?? 'active') !== 'active') {
        http_response_code(404);
        exit('Não encontrado');
    }
    activity(ActivityLog::CALENDAR_EXPORT, ['imovel_id' => (int) $property['id']]);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="casalivre-' . property_ref($property) . '.ics"');
    echo export_ics($property);
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/calendario\.ics$#', $path, $m) && $method === 'GET') {
    http_response_code(404);
    exit('Não encontrado');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/ical/importar$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $property = property_for_user((int) $user['id'], house_id($m[1]));
    $url = trim((string) ($_POST['external_ical_url'] ?? ''));
    $feedTitle = trim((string) ($_POST['feed_title'] ?? ''));
    $message = t('cal.link_saved');
    $messageTone = 'ok';
    $error = null;
    if ($url !== '') {
        $error = HouseRepository::addFeed((int) $property['id'], (int) $user['id'], $url, $feedTitle);
        if ($error === 'limit') {
            $message = t('cal.feed_limit');
            $messageTone = 'error';
        } elseif ($error === 'duplicate') {
            $message = t('cal.feed_duplicate');
            $messageTone = 'error';
        } elseif ($error === 'title') {
            $message = t('cal.feed_title_required');
            $messageTone = 'error';
        } else {
            $body = exported_calendar_body($url);
            if ($body === null) {
                $body = CalendarSync::fetch($url);
            }
            if ($body === null && isset($_POST['ics_body'])) {
                $body = (string) $_POST['ics_body'];
            }
            $feedId = null;
            foreach (HouseRepository::feeds((int) $property['id']) as $feed) {
                if ($feed['url'] === $url) {
                    $feedId = (int) $feed['id'];
                }
            }
            $now = date('Y-m-d H:i:s');
            if (is_string($body) && $body !== '') {
                $count = import_ics((int) $property['id'], $body);
                if ($feedId) {
                    HouseRepository::markFeed($feedId, 'OK', $now);
                }
                $message = t('cal.imported') . ' ' . $count . ' ' . t('cal.blocked_dates');
                activity(ActivityLog::CALENDAR_SYNC, [
                    'imovel_id' => (int) $property['id'],
                    'url' => $url,
                    'novas' => $count,
                    'resultado' => 'ok',
                ]);
            } else {
                if ($feedId) {
                    HouseRepository::markFeed($feedId, 'NOK', $now);
                }
                $message = t('cal.import_fail');
                $messageTone = 'error';
            }
        }
    }
    activity(ActivityLog::CALENDAR_FEED_ADD, ['imovel_id' => (int) $property['id'], 'titulo' => $feedTitle, 'url' => $url, 'resultado' => $message], null, is_array($error ?? null) ? ['feed' => $error['feed'], 'link' => $error['link'][0]['new']['external_ical_url'] ?? null] : null);
    view('calendar', [
        'property' => HouseRepository::find((int) $property['id']),
        'days' => HouseRepository::calendarDays((int) $property['id']),
        'feeds' => HouseRepository::feeds((int) $property['id']),
        'message' => $message,
        'message_tone' => $messageTone,
    ]);
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/ical/(\d+)/excluir$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    property_for_user((int) $user['id'], house_id($m[1]));
    $gone = HouseRepository::removeFeed(house_id($m[1]), (int) $user['id'], (int) $m[2]);
    $released = HouseRepository::nightAudit(HouseRepository::releaseImported(house_id($m[1])));
    if ($gone['feed']) {
        activity(ActivityLog::CALENDAR_FEED_REMOVE, [
            'imovel_id' => house_id($m[1]),
            'feed_id' => (int) $m[2],
        ], [
            'feed' => $gone['feed'],
            'link' => $gone['link'][0]['old']['external_ical_url'] ?? null,
            'noites' => $released['anterior'],
        ], [
            'link' => $gone['link'][0]['new']['external_ical_url'] ?? null,
            'noites' => $released['atual'],
        ]);
    }
    foreach (HouseRepository::feeds(house_id($m[1])) as $feed) {
        $body = CalendarSync::fetch((string) $feed['url']);
        if (!is_string($body)) {
            continue;
        }
        import_ics(house_id($m[1]), $body);
        HouseRepository::markFeed((int) $feed['id'], 'OK', date('Y-m-d H:i:s'));
    }
    redirect('/imoveis/' . $m[1] . '/calendario');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/capa$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    property_for_user((int) $user['id'], house_id($m[1]));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['fotos'] ?? [])))));
    if (count($ids) === 1) {
        $changed = PhotoRepository::makePrimary(house_id($m[1]), $ids[0]);
        if ($changed !== []) {
            activity(ActivityLog::PHOTO_COVER, ['imovel_id' => house_id($m[1])], array_column($changed, 'old'), array_column($changed, 'new'));
        }
    }
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/(\d+)/capa$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    property_for_user((int) $user['id'], house_id($m[1]));
    $changed = PhotoRepository::makePrimary(house_id($m[1]), (int) $m[2]);
    if ($changed !== []) {
        activity(ActivityLog::PHOTO_COVER, ['imovel_id' => house_id($m[1])], array_column($changed, 'old'), array_column($changed, 'new'));
    }
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/avaliar$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $property = HouseRepository::find(house_id($m[1]));
    if (!$property || ($property['status'] ?? 'active') !== 'active') {
        http_response_code(404);
        exit('Imóvel não encontrado.');
    }
    $score = (int) ($_POST['score'] ?? 0);
    $author = trim((string) ($_POST['author'] ?? 'Hóspede'));
    if ($score >= 1 && $score <= 10) {
        HouseRepository::addReview((int) $property['id'], $author === '' ? 'Hóspede' : $author, $score, trim((string) ($_POST['comment'] ?? '')));
        activity(ActivityLog::REVIEW, ['imovel_id' => (int) $property['id'], 'nota' => $score, 'autor' => $author === '' ? 'Hóspede' : $author]);
    }
    $back = safe_search_return((string) ($_POST['volta'] ?? ''));
    redirect('/imoveis/' . $m[1] . ($back !== '/' ? '?' . http_build_query(['volta' => $back]) : ''));
}

http_response_code(404);
view('message', ['title' => t('page.missing'), 'message' => t('page.missing_body')]);
return null;
}

function house_id(string $ref): int
{
    static $cache = [];
    if (isset($cache[$ref])) {
        return $cache[$ref];
    }
    $row = HouseRepository::findByPublicHash($ref);
    if (!$row || ($row['status'] ?? 'active') !== 'active') {
        http_response_code(404);
        view('message', ['title' => 'Imóvel não encontrado', 'message' => 'Este anúncio não existe.']);
        exit;
    }
    $cache[$ref] = (int) $row['id'];
    return $cache[$ref];
}

function property_for_user(int $userId, int $id): array
{
    $row = HouseRepository::findByUser($userId, $id);
    if (!$row) {
        http_response_code(404);
        exit('Imóvel não encontrado.');
    }
    return $row;
}

function calendar_previous(int $propertyId, array $input, array $property): array
{
    $start = parse_date((string) ($input['inicio'] ?? $input['day'] ?? ''));
    $end = parse_date((string) ($input['fim'] ?? '')) ?? $start;
    if ($start === null) {
        return ['minimo_noites' => (int) ($property['min_nights'] ?? 1)];
    }
    if ($end !== null && $end < $start) {
        [$start, $end] = [$end, $start];
    }
    $cursor = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end ?? $start);
    $nights = [];
    for ($day = $cursor; $day <= $last; $day = $day->modify('+1 day')) {
        $nights[] = $day->format('Y-m-d');
        if (count($nights) > 400) {
            break;
        }
    }
    $byDay = HouseRepository::calendarForNights($propertyId, $nights);
    $prices = [];
    $mins = [];
    $blocked = [];
    foreach ($nights as $day) {
        $row = $byDay[$day] ?? null;
        $prices[$day] = $row && $row['price'] !== null ? (int) $row['price'] : (int) $property['nightly_price'];
        $mins[$day] = $row && $row['min_nights'] !== null ? (int) $row['min_nights'] : (int) $property['min_nights'];
        $blocked[$day] = (int) ($row['blocked'] ?? 0);
    }
    return ['diaria' => $prices, 'minimo_noites' => $mins, 'bloqueado' => $blocked];
}

function calendar_current(array $antes, array $input): array
{
    $atual = $antes;
    $action = (string) ($input['acao'] ?? '');
    $min = (int) ($input['min_nights'] ?? 0);
    if ($min > 0 && isset($atual['minimo_noites']) && is_array($atual['minimo_noites'])) {
        foreach ($atual['minimo_noites'] as $day => $value) {
            $atual['minimo_noites'][$day] = $min;
        }
    } elseif ($min > 0) {
        $atual['minimo_noites'] = $min;
    }
    if ($action === 'salvar' && trim((string) ($input['price'] ?? '')) !== '' && isset($atual['diaria']) && is_array($atual['diaria'])) {
        $money = parse_money((string) $input['price']);
        foreach ($atual['diaria'] as $day => $value) {
            $atual['diaria'][$day] = $money;
        }
    }
    if (($action === 'bloquear' || $action === 'abrir') && isset($atual['bloqueado']) && is_array($atual['bloqueado'])) {
        $flag = $action === 'bloquear' ? 1 : 0;
        foreach ($atual['bloqueado'] as $day => $value) {
            $atual['bloqueado'][$day] = $flag;
        }
    }
    return $atual;
}

function save_calendar(array $property, array $input): array
{
    $logs = [];
    $done = static function (string $message) use (&$logs): array {
        return ['message' => $message, 'logs' => $logs];
    };
    $propertyId = (int) $property['id'];
    $max = max_calendar_day($property);
    $removed = HouseRepository::clearBlockedAfter($propertyId, $max);
    if ($removed !== []) {
        $audit = HouseRepository::nightAudit($removed);
        $logs[] = ['code' => ActivityLog::CALENDAR_UPDATE, 'extra' => ['motivo' => 'limite'], 'anterior' => $audit['anterior'], 'atual' => ['estado' => 'removida']];
    }
    $today = date('Y-m-d');
    $action = (string) ($input['acao'] ?? '');
    $start = parse_date((string) ($input['inicio'] ?? $input['day'] ?? ''));
    $end = parse_date((string) ($input['fim'] ?? '')) ?? $start;
    $hasPrice = isset($input['price']) && $input['price'] !== '';
    $hasMin = isset($input['min_nights']) && $input['min_nights'] !== '';
    if ($start !== null && $end !== null && $end < $start) {
        [$start, $end] = [$end, $start];
    }
    if ($start !== null && $end !== null && ($start < $today || $end > $max)) {
        return $done(t('cal.range') . ' ' . format_date($max) . '.');
    }
    if ($hasMin && $start !== null && $end !== null && ($action === '' || $action === 'salvar')) {
        $audit = HouseRepository::applyMinNights($propertyId, $start, $end, max(1, (int) $input['min_nights']));
        $logs[] = ['code' => ActivityLog::CALENDAR_MIN_NIGHTS, 'extra' => ['minimo_noites' => max(1, (int) $input['min_nights'])], 'anterior' => $audit['anterior'], 'atual' => $audit['atual']];
    } elseif ($hasMin && $start === null) {
        $changed = HouseRepository::updateMinNights($propertyId, max(1, (int) $input['min_nights']));
        $pair = $changed[0] ?? null;
        if ($pair) {
            $logs[] = [
                'code' => ActivityLog::CALENDAR_MIN_NIGHTS,
                'extra' => ['minimo_noites' => max(1, (int) $input['min_nights'])],
                'anterior' => ['minimo_noites' => (int) ($pair['old']['min_nights'] ?? 0)],
                'atual' => ['minimo_noites' => (int) ($pair['new']['min_nights'] ?? 0)],
            ];
        }
    }
    if ($action === '' || ($action === 'salvar' && !$hasPrice)) {
        return $done(t('cal.saved'));
    }
    if ($action === 'salvar') {
        $action = 'preco';
    }
    if ($start === null || $end === null) {
        return $done(t('cal.need_range'));
    }
    if ($end < $start) {
        [$start, $end] = [$end, $start];
    }
    if ($start < $today || $end > $max) {
        return $done(t('cal.range') . ' ' . format_date($max) . '.');
    }
    $price = null;
    if ($action === 'preco') {
        if (!isset($input['price']) || $input['price'] === '') {
            return $done(t('cal.need_price'));
        }
        $price = parse_money((string) $input['price']);
        if ($price < 1 || $price > 999999) {
            return $done(t('cal.price_max'));
        }
    } elseif (!in_array($action, ['bloquear', 'abrir'], true)) {
        return $done(t('cal.need_range'));
    }
    $result = HouseRepository::applyRange($propertyId, $start, $end, $action, $price);
    if ($result['count'] === 0 && in_array($action, ['bloquear', 'abrir'], true)) {
        return $done(t('cal.sync_locked'));
    }
    $code = match ($action) {
        'preco' => ActivityLog::CALENDAR_PRICE,
        'bloquear' => ActivityLog::CALENDAR_BLOCK,
        default => ActivityLog::CALENDAR_UNBLOCK,
    };
    $logs[] = ['code' => $code, 'extra' => $action === 'preco' ? ['diaria' => $price] : [], 'anterior' => $result['anterior'], 'atual' => $result['atual']];
    return $done(t('cal.saved') . ' (' . $result['count'] . ')');
}

function run_search(bool $log = true): array
{
    $filters = [
        'localidade' => trim((string) ($_GET['localidade'] ?? '')),
        'checkin' => (string) ($_GET['checkin'] ?? ''),
        'checkout' => (string) ($_GET['checkout'] ?? ''),
        'hospedes' => (int) ($_GET['hospedes'] ?? 0),
        'raio' => (int) ($_GET['raio'] ?? 0),
        'preco_min' => (int) ($_GET['preco_min'] ?? 0),
        'preco_max' => (int) ($_GET['preco_max'] ?? 0),
        'score_min' => (float) ($_GET['score_min'] ?? 0),
        'amenidades' => array_values(array_filter((array) ($_GET['amenidade'] ?? []), 'is_string')),
    ];
    $searched = $filters['localidade'] !== '' || $filters['checkin'] !== '' || $filters['checkout'] !== '';
    $facets = [];
    $results = $searched ? search_properties($filters, $facets) : [];
    if ($searched && $log) {
        activity(ActivityLog::SEARCH, $filters + ['resultados' => count($results)]);
    }
    return [$filters, $results, $searched, $facets];
}

function search_properties(array $filters, ?array &$facets = null): array
{
    $localidade = $filters['localidade'];
    $center = $localidade !== '' ? CityRepository::match($localidade) : null;
    $source = HouseRepository::all();
    $nights = nights_between($filters['checkin'], $filters['checkout']);
    $centerFold = $center ? fold((string) $center['name']) : '';
    $labelFold = $center ? fold((string) $center['label']) : '';
    $displayFold = $center ? fold($center['name'] . ', ' . $center['uf']) : '';
    $pool = [];
    foreach ($source as $property) {
        $cityFold = fold((string) $property['city']);
        if ($center && $filters['raio'] > 0) {
            $distance = haversine_km((float) $center['lat'], (float) $center['lng'], (float) $property['lat'], (float) $property['lng']);
            if ($distance > $filters['raio']) {
                continue;
            }
            $property['distance_km'] = round($distance, 1);
        } elseif ($center && $cityFold !== $centerFold && $cityFold !== $labelFold && $cityFold !== $displayFold) {
            continue;
        } elseif (!$center && $localidade !== '' && !str_contains($cityFold . ' ' . fold((string) $property['address']), fold($localidade))) {
            continue;
        }
        if ($filters['hospedes'] > 0 && (int) $property['max_guests'] < $filters['hospedes']) {
            continue;
        }
        $pool[] = $property;
    }
    $ids = array_map(static fn (array $property): int => (int) $property['id'], $pool);
    $calendars = $nights ? HouseRepository::calendarForProperties($ids, $nights) : [];
    $quoted = [];
    foreach ($pool as $property) {
        $id = (int) $property['id'];
        $quote = $nights
            ? stay_quote($property, $nights, $calendars[$id] ?? [])
            : ['nights' => 0, 'total' => (int) $property['nightly_price'], 'nightly' => (int) $property['nightly_price']];
        if ($nights && $quote === null) {
            continue;
        }
        if ($filters['preco_min'] > 0 && $quote['nightly'] < $filters['preco_min']) {
            continue;
        }
        if ($filters['preco_max'] > 0 && $quote['nightly'] > $filters['preco_max']) {
            continue;
        }
        $property['quote'] = $quote;
        $quoted[] = $property;
    }
    $quotedIds = array_map(static fn (array $property): int => (int) $property['id'], $quoted);
    $scores = HouseRepository::averageScores($quotedIds);
    $photos = PhotoRepository::primaryPaths($quotedIds);
    $wanted = array_values(array_filter(
        $filters['amenidades'] ?? [],
        static fn (string $key): bool => in_array($key, Amenities::keys(), true)
    ));
    $counts = array_fill_keys(Amenities::keys(), 0);
    $rows = [];
    foreach ($quoted as $property) {
        $id = (int) $property['id'];
        $score = $scores[$id] ?? null;
        if ($filters['score_min'] > 0 && ($score === null || $score < $filters['score_min'])) {
            continue;
        }
        $selected = Amenities::selected($property);
        foreach (Amenities::keys() as $key) {
            if (!empty($selected[$key])) {
                $counts[$key]++;
            }
        }
        foreach ($wanted as $key) {
            if (empty($selected[$key])) {
                continue 2;
            }
        }
        $property['photo'] = $photos[$id] ?? null;
        $property['score'] = $score;
        $rows[] = $property;
    }
    if ($facets !== null) {
        $facets = $counts;
    }
    return $rows;
}
