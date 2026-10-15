<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/GeoSalt.php';
// Fail closed: fora de dev/local, sem GEO_SALT válido (>= 32 caracteres) o app não atende.
GeoSalt::enforce();
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/CityRepository.php';
require_once __DIR__ . '/../src/CepRepository.php';
require_once __DIR__ . '/../src/UserRepository.php';
PasswordPolicy::$minLength = max(1, Settings::int('password_min_length') ?: 8);
require_once __DIR__ . '/../src/Mailer.php';
AccountFlow::$enqueue = static function (string $type, string $email): void {
    Mailer::enqueueAccountMail($type, $email);
};
require_once __DIR__ . '/../src/HouseRepository.php';
require_once __DIR__ . '/../src/PhotoRepository.php';
require_once __DIR__ . '/../src/PhotoStorage.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/SearchService.php';
require_once __DIR__ . '/../src/GrupoResolvedor.php';
require_once __DIR__ . '/../src/Redirect.php';
require_once __DIR__ . '/../src/GrupoUrl.php';
require_once __DIR__ . '/../src/CalendarService.php';
reject_oversize_upload();
require_once __DIR__ . '/../src/HouseService.php';
require_once __DIR__ . '/../src/Amenities.php';
require_once __DIR__ . '/../src/CalendarSync.php';
require_once __DIR__ . '/../src/Api.php';
require_once __DIR__ . '/../src/ActivityLog.php';
require_once __DIR__ . '/../src/SecureSession.php';
require_once __DIR__ . '/../src/ExceptionShield.php';

// Stack traces sem valores de argumentos (evita vazar tokens/senhas em logs de erro).
ini_set('zend.exception_ignore_args', '1');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
// Tiles do mapa (PMTiles): em produção o nginx serve o arquivo; em dev/local (php -S) esta rota, com Range.
// URL exata (sem parâmetro de caminho); fora de dev/local cai no 404 normal.
if ($path === '/tiles/brasil.pmtiles') {
    require_once __DIR__ . '/../src/MapTiles.php';
    if (MapTiles::devRouteEnabled()) {
        MapTiles::serve($method, isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : null);
        exit;
    }
}

// Sessão só com cookie de sessão ou rota autenticada; páginas com formulário abrem a sessão
// ao pedir o token CSRF (csrf_token() em view()).
if (isset($_COOKIE[session_name()]) || casa_privado($path, $method)) {
    SecureSession::start();
}
// Log por requisição da API iniciado ANTES do pipeline de segurança, para que as
// respostas 401/419/429 geradas pelos middlewares (que encerram com exit) também
// sejam registradas pelo shutdown hook do RequestLog.
if (str_starts_with($path, '/api')) {
    RequestLog::start($path, $method, api_body());
}
expire_user_session();
ExceptionShield::register();

// CORS para frontend em outro servidor.
// Lista explícita de origens em CORS_ORIGINS (ou CORS_ORIGIN, legado), separadas por vírgula,
// ex.: https://app.casalivre.com.br,https://admin.casalivre.com.br
// Comparação exata (esquema+host+porta). '*' NÃO é aceito, pois as credenciais são enviadas.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    header('Vary: Origin', false);
    if (in_array($origin, cors_allowed_origins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
        header('Access-Control-Max-Age: 86400');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Content-Security-Policy: ' . csp_header());

require_once __DIR__ . '/../src/LoginRateLimiter.php';
require_once __DIR__ . '/../seguranca/Seguranca.php';
require_once __DIR__ . '/../src/bootstrap.php';

return Seguranca::pipeline(
    [
        'campos_puros' => ['password', 'password_confirm', '_csrf', 'external_ical_url'],
        'sem_csrf' => [],
        'login' => [
            ['POST', '/entrar'],
            ['POST', '/api/sessao'],
        ],
        // [método, caminho, máximo, janela (s), balde] por IP (IPv6 por /64).
        'api_limites' => [
            ['GET', '#^/api/cidades$#', 60, 60, '/api/cidades'],
            ['GET', '#^/api/ceps$#', 60, 60, '/api/ceps'],
            ['GET', '#^/api/busca$#', 60, 60, '/api/busca'],
            ['POST', '#^/api/auth/senha/esqueci$#', 5, 900, '/api/auth/senha/esqueci'],
            ['POST', '#^/api/users$#', 10, 3600, '/api/users'],
            ['POST', '#^/api/imoveis/[a-f0-9]{16}/avaliar$#', 10, 3600, '/api/imoveis/*/avaliar'],
            ['POST', '#^/api/casas/[a-f0-9]{16}/fotos$#', 30, 600, '/api/casas/*/fotos'],
            // Upload pelo formulário web: mesmo balde da API (30 por 10 min por IP, somando os dois).
            ['POST', '#^/imoveis/[a-f0-9]{16}/fotos$#', 30, 600, '/api/casas/*/fotos'],
            // Avaliação pelo formulário web: mesmo balde da API (10 por hora por IP, somando os dois).
            ['POST', '#^/imoveis/[a-f0-9]{16}/avaliar$#', 10, 3600, '/api/imoveis/*/avaliar'],
            // Formulários web (mesma camada). Atrás de proxy, REMOTE_ADDR precisa ser o IP real (real_ip no nginx).
            ['POST', '#^/cadastro$#', 10, 3600, '/cadastro'],
            ['POST', '#^/senha/esqueci$#', 5, 900, '/senha/esqueci'],
        ],
        'bloqueado' => static fn (): bool => LoginRateLimiter::blocked(),
        'privado' => static fn (string $path, string $method): bool => casa_privado($path, $method),
        'recusar' => static function (string $code, string $path): never {
            casa_recusar($code, $path);
        },
    ],
    ['path' => $path, 'method' => $method],
    static function (array $request): mixed {
        try {
            return dispatch((string) $request['path'], (string) $request['method']);
        } catch (ImovelNaoEncontrado $e) {
            // 404 das rotas web que resolvem imóvel (HouseRepository::*OrFail), com as mesmas respostas de antes.
            http_response_code(404);
            if ($e->motivo === ImovelNaoEncontrado::DONO) {
                exit('Imóvel não encontrado.');
            }
            view('message', ['title' => 'Imóvel não encontrado', 'message' => 'Este anúncio não existe.']);
            exit;
        }
    }
);

/** @return list<string> */
function cors_allowed_origins(): array
{
    $raw = (string) (getenv('CORS_ORIGINS') ?: getenv('CORS_ORIGIN') ?: '');
    $list = [];
    foreach (explode(',', $raw) as $item) {
        $item = rtrim(trim($item), '/');
        if ($item === '' || $item === '*') {
            continue;
        }
        $parts = parse_url($item);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['path'])) {
            continue;
        }
        $list[] = $item;
    }
    return $list;
}

function casa_privado(string $path, string $method): bool
{
    if (in_array($path, ['/painel', '/conta', '/conta/excluir', '/imoveis/novo', '/sair', '/admin/tema'], true)) {
        return true;
    }
    // Grupos (PR 6): admin, área do membro e aceite do dono.
    if (preg_match('#^/(admin/)?grupos(/|$)#', $path) === 1 || preg_match('#^/imoveis/[a-f0-9]{16}/grupo/#', $path) === 1) {
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
 * - 'too_large'  -> 413 (corpo acima de BodyLimit::MAX_BYTES; JSON em /api, HTML fora) + exit
 * - 'length_required' -> 411 (Transfer-Encoding ou escrita sem Content-Length) + exit
 * - 'api_rate_limit' -> 429 JSON + Retry-After (enviado pelo ApiRateLimit) + exit
 * - 'rate_limit' -> 429 + Retry-After (enviado pelo RateLimit) (JSON em /api, tela de login fora) + exit
 * - 'login'      -> 302 para /entrar + exit
 * - outros       -> 401 JSON + exit
 */
function casa_recusar(string $code, string $path): never
{
    $api = str_starts_with($path, '/api');
    if ($code === 'csrf') {
        $api ? casa_json_error(419, 'Token de segurança inválido.')
            : casa_html_error(419, 'Pedido recusado', 'O token de segurança não confere. Recarregue a página e tente de novo.');
    }
    if ($code === 'api_rate_limit') {
        // Retry-After enviado pelo ApiRateLimit; formulários web recebem o mesmo 429 em HTML.
        $api ? casa_json_error(429, 'Muitas requisições. Tente novamente em instantes.')
            : casa_html_error(429, 'Muitas tentativas', 'Muitas requisições. Tente novamente em instantes.');
    }
    if ($code === 'too_large') {
        $api ? casa_json_error(413, 'Corpo da requisição grande demais.')
            : casa_html_error(413, 'Envio grande demais', 'O conteúdo enviado passa do limite permitido.');
    }
    if ($code === 'length_required') {
        $api ? casa_json_error(411, 'Content-Length obrigatório.')
            : casa_html_error(411, 'Pedido recusado', 'O envio precisa informar o tamanho do conteúdo.');
    }
    if ($code === 'rate_limit') {
        $message = LoginRateLimiter::message();
        if ($api) {
            casa_json_error(429, $message);
        }
        http_response_code(429);
        view('login', ['error' => $message]);
        exit;
    }
    if ($code === 'login') {
        header('Location: /entrar');
        exit;
    }
    casa_json_error(401, 'Não autenticado.');
}

/** Resposta de erro padronizada da API: {"error": "..."}. */
function casa_json_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function casa_html_error(int $status, string $title, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    if (!headers_sent() && !preg_grep('/^cache-control:/i', headers_list())) {
        header('Cache-Control: private, no-cache');
    }
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>' . $e($title) . '</title><link rel="stylesheet" href="' . $e(asset_url('/assets/app.css')) . '"><body class="err-page"><h1>' . $e($title) . '</h1><p>' . $e($message) . '</p><p><a href="/">Voltar ao início</a></p></body></html>';
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
    $sent = AccountFlow::askReset($email, Settings::int('password_reset_minutes'));
    if ($sent) {
        activity(ActivityLog::PASSWORD_RESET_ASK, ['email' => $email]);
    }
    view('password_forgot', ['error' => null, 'link' => expose_account_links() ? ($sent['link'] ?? null) : null, 'expires' => $sent['expires'] ?? null, 'notice' => t('auth.reset_missing')]);
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
        Redirect::withQuery('/busca');
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
    // Página do mapa (MapLibre + PMTiles): só o esqueleto; os pins vêm de /api/busca no navegador (GeoJSON com
    // clusters). A busca é registrada uma vez, pela API; a página não roda a busca no servidor.
    $filters = search_filters_from_query();
    view('map', [
        'filters' => $filters,
        'searched' => $filters['localidade'] !== '' || $filters['checkin'] !== '' || $filters['checkout'] !== '',
    ]);
    return null;
}

if ($path === '/busca' && $method === 'GET') {
    // Resultados vêm da API (/api/busca). A view só monta o formulário e carrega via JS.
    $filters = search_filters_from_query();
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

// Vitrine do grupo (Sprint 3, PR 8): /g/<slug>/ (home), /g/<slug>/busca, /g/<slug>/busca/mapa e /g/<slug>/imoveis/<ref>.
// Só imóveis ativos do grupo (a busca vai para /api/busca?grupo=<slug>); os links ficam na vitrine. Grupo por
// GrupoResolvedor::grupoDoSlugNoHost (no domínio verificado de um grupo, só o slug dele); tema pelo GrupoResolvedor
// (caminho /g/<slug>/). Slug inválido, inexistente ou de outro grupo no domínio: 404 com o tema da plataforma (ou do
// domínio). Imóvel inativo, de outro grupo ou inexistente: 404 com o tema do grupo.
if (preg_match('#^/g/([^/]+)(/|/busca|/busca/mapa|/imoveis/([a-f0-9]{16}))?$#', $path, $m) && $method === 'GET') {
    // No domínio verificado de um grupo, só a vitrine dele: /g/<slug de outro grupo>/ é 404 (como slug inexistente).
    $vGroup = GrupoResolvedor::grupoDoSlugNoHost($m[1], $_SERVER['HTTP_HOST'] ?? null);
    // 404 da vitrine: sem grupo, tema da plataforma; com grupo ($vData), o tema dele.
    $vMissing = static function (array $data = []): void {
        http_response_code(404);
        view('message', ['title' => t('page.missing'), 'message' => t('page.missing_body')] + $data);
    };
    if ($vGroup === null) {
        $vMissing();
        return null;
    }
    $vBase = '/g/' . $vGroup['slug'];
    $vSuffix = $m[2] ?? '';
    if ($vSuffix === '') {
        // A2: mantém a query; $vBase vem só do slug validado (Redirect::withQuery não usa Host nem a URI inteira).
        Redirect::withQuery($vBase . '/');
    }
    $vApi = '/api/busca?' . http_build_query(array_merge($_GET, ['grupo' => $vGroup['slug']]));
    $vData = ['vitrine_base' => $vBase, 'vitrine_grupo' => $vGroup, 'title' => (string) $vGroup['name']];
    if ($vSuffix === '/') {
        view('home', $vData + [
            'vitrine_imoveis' => GroupRepository::properties((int) $vGroup['id']),
            'filters' => ['localidade' => '', 'checkin' => '', 'checkout' => '', 'hospedes' => 0],
        ]);
        return null;
    }
    if ($vSuffix === '/busca' || $vSuffix === '/busca/mapa') {
        $filters = search_filters_from_query();
        $searched = $filters['localidade'] !== '' || $filters['checkin'] !== '' || $filters['checkout'] !== '';
        view($vSuffix === '/busca' ? 'search' : 'map', $vData + [
            'filters' => $filters, 'searched' => $searched, 'use_api' => true, 'vitrine_api' => $vApi,
        ]);
        return null;
    }
    $vHouse = GroupRepository::activePropertyInGroup((int) $vGroup['id'], (string) $m[3]);
    if ($vHouse === null) {
        $vMissing($vData);
        return null;
    }
    view('property', $vData + [
        'hash' => (string) $m[3], 'imovel' => $vHouse, 'use_api' => true,
        'checkin' => (string) ($_GET['checkin'] ?? ''), 'checkout' => (string) ($_GET['checkout'] ?? ''),
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
    $result = AccountFlow::register($_POST, Settings::int('email_verify_minutes'));
    if ($result['error']) {
        view('register', ['error' => account_text($result['error']), 'old' => $registerOld]);
        return null;
    }
    view('verify_pending', ['expires' => $result['expires']]);
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
    // Web: grava LOGIN_FAIL para conta apagada/inativa e reenvia a ativação de conta pendente (ver LoginAttempt).
    $res = LoginAttempt::run($email, (string) ($_POST['password'] ?? ''), logInactive: true, ensureActivation: true);
    $res->registrar();
    if ($res->tipo === 'ok') {
        redirect('/painel');
    }
    if ($res->tipo === 'pending') {
        view('verify_pending', ['expires' => $res->dados['expires']]);
        return null;
    }
    if ($res->tipo === 'rate_limited') {
        http_response_code(429);
    }
    view('login', ['error' => $res->mensagem]);
    return null;
}

if ($path === '/sair' && $method === 'POST') {
    csrf_check();
    activity(ActivityLog::LOGOUT);
    unset($_SESSION['user_id']);
    redirect('/');
}

// Tema da plataforma (Etapa 2): só admin (users.is_admin = 1); troca por POST com CSRF (regra em ThemeSet).
if ($path === '/admin/tema' && ($method === 'GET' || $method === 'POST')) {
    $user = require_admin();
    $nomes = Theme::available();
    $temas = [];
    foreach ($nomes as $nome) {
        $temas[$nome] = Theme::brand($nome)['rotulo'];
    }
    $atual = Theme::current();
    if ($method === 'POST') {
        csrf_check();
        $res = ThemeSet::run($_POST['tema'] ?? '', $atual, $nomes);
        $res->registrar();
        if ($res->tipo !== 'ok') {
            http_response_code($res->tipo === 'invalido' ? 422 : 409);
            view('admin_theme', ['user' => $user, 'temas' => $temas, 'atual' => $atual, 'error' => $res->mensagem, 'ok' => false]);
            return null;
        }
        redirect('/admin/tema?ok=1');
    }
    view('admin_theme', ['user' => $user, 'temas' => $temas, 'atual' => $atual, 'error' => null, 'ok' => isset($_GET['ok'])]);
    return null;
}

// Grupos (Sprint 3, PR 6). Regras e permissões em src/Acoes/Grupo{Admin,Gestor,Dono}.php, leituras em GroupRepository;
// aqui só login, CSRF e tela.
// Grupo inexistente e grupo de que o usuário não é membro dão o mesmo 404.
$grupoNaoEncontrado = static function (?array $user = null): never {
    http_response_code(404);
    view('message', ['title' => t('page.missing'), 'message' => t('page.missing_body'), 'user' => $user]);
    exit;
};
$grupoResposta = static function (Resultado $res, string $volta, callable $tela) use ($grupoNaoEncontrado): void {
    $res->registrar();
    if ($res->tipo === 'nao_encontrado') {
        $grupoNaoEncontrado();
    }
    if ($res->tipo !== 'ok') {
        http_response_code($res->tipo === 'invalido' ? 422 : 409);
        $tela($res->mensagem, null);
        exit;
    }
    $_SESSION['grupo_aviso'] = $res->mensagem ?? 'Salvo.';
    redirect($volta);
};
// Tela /grupos do usuário (seus grupos, convites e imóveis em grupos), usada no GET e no erro do aceite.
$grupoTelaDono = static function (array $user, ?string $ok, ?string $error): void {
    view('groups', [
        'user' => $user, 'groups' => GroupRepository::forUser((int) $user['id']), 'ok' => $ok, 'error' => $error,
        'invites' => GroupRepository::invitesForOwner((int) $user['id']), 'linked' => GroupRepository::ownerLinked((int) $user['id']),
    ]);
};
$grupoAviso = static function (): ?string {
    $msg = $_SESSION['grupo_aviso'] ?? null;
    unset($_SESSION['grupo_aviso']);
    return is_string($msg) ? $msg : null;
};

if ($path === '/admin/grupos' && ($method === 'GET' || $method === 'POST')) {
    $user = require_admin();
    $tela = static function (?string $error, ?string $ok) use ($user): void {
        view('admin_groups', ['user' => $user, 'groups' => GroupRepository::all(), 'temas' => Theme::available(), 'error' => $error, 'ok' => $ok, 'posted' => $_POST]);
    };
    if ($method === 'POST') {
        csrf_check();
        $res = GrupoAdmin::create($user, $_POST['slug'] ?? null, $_POST['nome'] ?? null, $_POST['tema'] ?? null);
        $grupoResposta($res, '/admin/grupos/' . ($res->dados['slug'] ?? ''), $tela);
    }
    $tela(null, $grupoAviso());
    return null;
}

if (preg_match('#^/admin/grupos/([a-z0-9-]{1,40})(?:/(dominios|dominios/remover|dominios/token|dominios/verificar|membros|membros/remover))?$#', $path, $m) && ($method === 'GET' || $method === 'POST')) {
    $user = require_admin();
    $group = GroupRepository::findBySlug($m[1]);
    $acao = $m[2] ?? '';
    if ($group === null || ($acao !== '' && $method !== 'POST')) {
        $grupoNaoEncontrado($user);
    }
    $tela = static function (?string $error, ?string $ok) use ($user, $group): void {
        $fresh = GroupRepository::findBySlug($group['slug']) ?? $group;
        view('admin_group', [
            'user' => $user, 'group' => $fresh, 'temas' => Theme::available(), 'error' => $error, 'ok' => $ok,
            'domains' => GroupRepository::domainRows((int) $fresh['id']), 'members' => GroupRepository::members((int) $fresh['id']),
            'properties' => GroupRepository::properties((int) $fresh['id']), 'invites' => GroupRepository::invites((int) $fresh['id']),
            'vitrine_url' => GrupoUrl::home($fresh),
        ]);
    };
    if ($method === 'POST') {
        csrf_check();
        $res = match ($acao) {
            '' => GrupoAdmin::update($user, $group, $_POST['nome'] ?? null, $_POST['tema'] ?? null),
            'dominios' => GrupoAdmin::addDomain($user, $group, $_POST['dominio'] ?? null),
            'dominios/remover' => GrupoAdmin::removeDomain($user, $group, $_POST['dominio'] ?? null),
            'dominios/token' => GrupoAdmin::startDomainVerification($user, $group, $_POST['dominio'] ?? null),
            'dominios/verificar' => GrupoAdmin::requestDomainCheck($user, $group, $_POST['dominio'] ?? null),
            'membros' => GrupoAdmin::setMember($user, $group, $_POST['email'] ?? null, $_POST['papel'] ?? null),
            'membros/remover' => GrupoAdmin::removeMember($user, $group, $_POST['usuario'] ?? null),
        };
        $grupoResposta($res, '/admin/grupos/' . $group['slug'], $tela);
    }
    $tela(null, $grupoAviso());
    return null;
}

if ($path === '/grupos' && $method === 'GET') {
    $user = require_user();
    $grupoTelaDono($user, $grupoAviso(), null);
    return null;
}

if (preg_match('#^/grupos/([a-z0-9-]{1,40})(?:/(convites|convites/cancelar|imoveis/remover))?$#', $path, $m) && ($method === 'GET' || $method === 'POST')) {
    $user = require_user();
    $acao = $m[2] ?? '';
    if ($method === 'POST') {
        csrf_check();
    }
    $group = GroupRepository::findBySlug($m[1]);
    $role = $group === null ? null : GroupRepository::activeRole((int) $group['id'], (int) $user['id']);
    if ($group === null || $role === null || ($acao === '') === ($method === 'POST')) {
        $grupoNaoEncontrado($user);
    }
    $tela = static function (?string $error, ?string $ok) use ($user, $group, $role): void {
        view('group', [
            'user' => $user, 'group' => $group, 'role' => $role, 'error' => $error, 'ok' => $ok,
            'properties' => GroupRepository::properties((int) $group['id']),
            'invites' => $role === 'gestor' ? GroupRepository::invites((int) $group['id']) : [],
            'vitrine_url' => GrupoUrl::home($group),
        ]);
    };
    if ($method === 'POST') {
        $res = match ($acao) {
            'convites' => GrupoGestor::invite($user, $group, $_POST['imovel'] ?? null),
            'convites/cancelar' => GrupoGestor::cancelInvite($user, $group, $_POST['imovel'] ?? null),
            'imoveis/remover' => GrupoGestor::removeProperty($user, $group, $_POST['imovel'] ?? null),
        };
        $grupoResposta($res, '/grupos/' . $group['slug'], $tela);
    }
    $tela(null, $grupoAviso());
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/grupo/(aceitar|recusar|sair)$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $property = HouseRepository::findByUserOrFail((int) $user['id'], HouseRepository::idFromPublicRefOrFail($m[1]));
    $res = match ($m[2]) {
        'aceitar' => GrupoDono::accept($user, $property, $_POST['grupo'] ?? null),
        'recusar' => GrupoDono::reject($user, $property, $_POST['grupo'] ?? null),
        'sair' => GrupoDono::leave($user, $property),
    };
    $grupoResposta($res, '/grupos', static function (?string $error) use ($user, $grupoTelaDono): void {
        $grupoTelaDono($user, null, $error);
    });
}

if ($path === '/conta' && $method === 'GET') {
    view('account', ['error' => null, 'user' => require_user()]);
    return null;
}

if ($path === '/conta' && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $res = AccountUpdate::run($user, $_POST);
    $res->registrar();
    if ($res->tipo !== 'ok') {
        view('account', ['error' => $res->mensagem, 'user' => $user]);
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
        'imovel' => GroupRepository::activePropertyByRef($m[1]),
        'use_api' => true,
        'checkin' => (string) ($_GET['checkin'] ?? ''),
        'checkout' => (string) ($_GET['checkout'] ?? ''),
    ]);
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $propertyId = HouseRepository::idFromPublicRefOrFail($m[1]);
    $updated = HouseService::update((int) $user['id'], $propertyId, $_POST);
    if (isset($updated['error'])) {
        $property = HouseRepository::findByUser((int) $user['id'], $propertyId);
        view('property_form', [
            'error' => $updated['error'],
            'property' => $property,
            'posted' => $_POST,
            'cities' => cities(),
            'photos' => $property ? PhotoRepository::list((int) $property['id']) : [],
        ]);
        return null;
    }
    activity(ActivityLog::PROPERTY_UPDATE, ['imovel_id' => $propertyId, 'titulo' => (string) ($_POST['title'] ?? ''), 'cidade' => (string) ($_POST['city'] ?? '')] + HouseService::amenityChange($updated['anterior'] ?? [], $updated['atual'] ?? []), $updated['anterior'] ?? null, $updated['atual'] ?? null);
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/excluir$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    HouseService::remove((int) $user['id'], HouseRepository::idFromPublicRefOrFail($m[1]));
    redirect('/painel');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/editar$#', $path, $m) && $method === 'GET') {
    $user = require_user();
    $property = HouseRepository::findByUserOrFail((int) $user['id'], HouseRepository::idFromPublicRefOrFail($m[1]));
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
    $propertyId = HouseRepository::idFromPublicRefOrFail($m[1]);
    HouseRepository::findByUserOrFail((int) $user['id'], $propertyId);
    $createdPhotos = [];
    $saved = save_uploaded_photos((int) $user['id'], $propertyId, $_FILES['fotos'] ?? [], $createdPhotos);
    $sent = is_array($_FILES['fotos']['name'] ?? null) ? count(array_filter($_FILES['fotos']['name'], static fn ($name) => $name !== '')) : 0;
    if ($sent === 0) {
        $_SESSION['upload_notice'] = 'Nenhuma foto chegou. O envio é maior do que o limite do sistema.';
    } elseif ($saved < $sent) {
        $limit = Settings::int('max_photos_per_property') ?: 40;
        $_SESSION['upload_notice'] = "Salvas $saved de $sent fotos. Cada imóvel pode ter até $limit fotos, e cada arquivo até 16 MB (jpg, png ou webp).";
    }
    activity(ActivityLog::PHOTO_ADD, ['imovel_id' => $propertyId, 'enviadas' => $sent, 'salvas' => $saved], null, ['fotos' => $createdPhotos]);
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/excluir$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $propertyId = HouseRepository::idFromPublicRefOrFail($m[1]);
    HouseRepository::findByUserOrFail((int) $user['id'], $propertyId);
    $removed = PhotoRepository::deleteIds($propertyId, array_values(array_unique(array_map('intval', (array) ($_POST['fotos'] ?? [])))));
    foreach ($removed['old'] as $photo) {
        PhotoStorage::deletePhotoFiles($photo);
    }
    if ($removed['old'] !== []) {
        activity(ActivityLog::PHOTO_DELETE, ['imovel_id' => $propertyId, 'quantidade' => count($removed['old'])], ['fotos' => $removed['old']], ['estado' => 'removida']);
    }
    if ($removed['capa'] !== []) {
        activity(ActivityLog::PHOTO_COVER, ['imovel_id' => $propertyId], $removed['capa'][0]['old'] ?? null, $removed['capa'][0]['new'] ?? null);
    }
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/(\d+)/excluir$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $propertyId = HouseRepository::idFromPublicRefOrFail($m[1]);
    HouseRepository::findByUserOrFail((int) $user['id'], $propertyId);
    $removed = PhotoRepository::delete($propertyId, (int) $m[2]);
    if ($removed['old']) {
        PhotoStorage::deletePhotoFiles($removed['old']);
        activity(ActivityLog::PHOTO_DELETE, ['imovel_id' => $propertyId, 'foto_id' => (int) $removed['old']['id']], $removed['old'], ['estado' => 'removida']);
    }
    if ($removed['capa'] !== []) {
        activity(ActivityLog::PHOTO_COVER, ['imovel_id' => $propertyId], $removed['capa'][0]['old'] ?? null, $removed['capa'][0]['new'] ?? null);
    }
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'GET') {
    $user = require_user();
    $property = HouseRepository::findByUserOrFail((int) $user['id'], HouseRepository::idFromPublicRefOrFail($m[1]));
    // GET é somente leitura: a limpeza das noites além do horizonte acontece no POST
    // (CalendarService::save), no import_ics e no cron scripts/sync-calendars.php.
    view('calendar', [
        'property' => $property,
        'days' => HouseRepository::calendarDaysUntil((int) $property['id'], max_calendar_day($property)),
        'feeds' => HouseRepository::feeds((int) $property['id']),
        'message' => null,
    ]);
    return null;
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/calendario$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $property = HouseRepository::findByUserOrFail((int) $user['id'], HouseRepository::idFromPublicRefOrFail($m[1]));
    $res = CalendarSave::run($property, $_POST);
    $res->registrar();
    $message = $res->mensagem;
    $action = (string) ($_POST['acao'] ?? '');
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
    $property = HouseRepository::findByUserOrFail((int) $user['id'], HouseRepository::idFromPublicRefOrFail($m[1]));
    $url = trim((string) ($_POST['external_ical_url'] ?? ''));
    $feedTitle = trim((string) ($_POST['feed_title'] ?? ''));
    // Web: URL vazia vira "link salvo", CALENDAR_FEED_ADD sempre, CALENDAR_SYNC só em sucesso (ver ICalImport).
    $res = ICalImport::run($property, (int) $user['id'], $url, $feedTitle, Canal::Web);
    $res->registrar();
    $message = $res->mensagem;
    $messageTone = in_array($res->tipo, ['vazio', 'ok'], true) ? 'ok' : 'error';
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
    $propertyId = HouseRepository::idFromPublicRefOrFail($m[1]);
    HouseRepository::findByUserOrFail((int) $user['id'], $propertyId);
    ICalFeedRemove::remover($propertyId, (int) $user['id'], (int) $m[2], seguirSemFeed: true)->registrar();
    // SNAPSHOT: a web não trata 'nao_encontrado' de propósito. Feed inexistente ainda ressincroniza e
    // redireciona (302), como antes; tratar o desfecho mudaria o comportamento (a API responde 404).
    CalendarSync::resyncProperty($propertyId);
    redirect('/imoveis/' . $m[1] . '/calendario');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/capa$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $propertyId = HouseRepository::idFromPublicRefOrFail($m[1]);
    HouseRepository::findByUserOrFail((int) $user['id'], $propertyId);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['fotos'] ?? [])))));
    // Web: 0 ou 2+ fotos marcadas são ignoradas em silêncio (a API exige foto_id; ver CoverSet).
    if (count($ids) === 1) {
        CoverSet::run($propertyId, $ids[0])->registrar();
    }
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/fotos/(\d+)/capa$#', $path, $m) && $method === 'POST') {
    $user = require_user();
    csrf_check();
    $propertyId = HouseRepository::idFromPublicRefOrFail($m[1]);
    HouseRepository::findByUserOrFail((int) $user['id'], $propertyId);
    CoverSet::run($propertyId, (int) $m[2])->registrar();
    redirect('/imoveis/' . $m[1] . '/editar');
}

if (preg_match('#^/imoveis/([a-f0-9]{16})/avaliar$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $property = HouseRepository::find(HouseRepository::idFromPublicRefOrFail($m[1]));
    if (!$property || ($property['status'] ?? 'active') !== 'active') {
        http_response_code(404);
        exit('Imóvel não encontrado.');
    }
    // Web: nota inválida é ignorada em silêncio e segue para o redirect (a API responde 422; ver ReviewAdd).
    ReviewAdd::run(
        (int) $property['id'],
        (int) ($_POST['score'] ?? 0),
        trim((string) ($_POST['author'] ?? 'Hóspede')),
        trim((string) ($_POST['comment'] ?? ''))
    )->registrar();
    $back = safe_search_return((string) ($_POST['volta'] ?? ''));
    redirect('/imoveis/' . $m[1] . ($back !== '/' ? '?' . http_build_query(['volta' => $back]) : ''));
}

http_response_code(404);
view('message', ['title' => t('page.missing'), 'message' => t('page.missing_body')]);
return null;
}
