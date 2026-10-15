<?php

declare(strict_types=1);

// Helpers divididos por tema em src/helpers/. As funções que usam __DIR__ ficam aqui para não mudar o caminho.
require_once __DIR__ . '/helpers/url.php';

require_once __DIR__ . '/AppEnv.php';
require_once dirname(__DIR__) . '/conta/Auth/Password.php';
require_once dirname(__DIR__) . '/conta/Auth/Phone.php';
require_once dirname(__DIR__) . '/conta/Auth/Session.php';

require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/geo.php';
require_once __DIR__ . '/helpers/dates.php';
require_once __DIR__ . '/helpers/money.php';
require_once __DIR__ . '/helpers/photos.php';
require_once __DIR__ . '/helpers/ical.php';
require_once __DIR__ . '/helpers/page.php';

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

function view(string $template, array $data = []): void
{
    // C4 (PR 7): o HTML muda por Host (tema do grupo) e por sessão, então nenhum cache compartilhado guarda a página:
    // "Cache-Control: private, no-cache". Se já há Cache-Control (ex.: o no-store da sessão PHP), mantém o mais restrito.
    if (!headers_sent() && !preg_grep('/^cache-control:/i', headers_list())) {
        header('Cache-Control: private, no-cache');
    }
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
