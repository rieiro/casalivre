<?php

declare(strict_types=1);

function password_make(string $password): string
{
    return PasswordPolicy::hash($password);
}

function expire_user_session(): void
{
    $minutes = max(1, Settings::int('session_minutes') ?: 60);
    SessionGuard::expire($minutes);
    // Redefinição de senha incrementa users.session_version: sessões abertas antes saem.
    if (!empty($_SESSION['user_id'])
        && (int) ($_SESSION['session_version'] ?? 0) !== UserRepository::sessionVersion((int) $_SESSION['user_id'])) {
        unset($_SESSION['user_id'], $_SESSION['auth_at'], $_SESSION['session_version']);
        $_SESSION['session_expired'] = 1;
    }
}

function password_phrase(string $key): string
{
    return sprintf(t($key), max(1, PasswordPolicy::$minLength));
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

/** Só admin (users.is_admin = 1): sem login -> /entrar; logado sem permissão -> 403. */
function require_admin(): array
{
    $user = require_user();
    if ((int) ($user['is_admin'] ?? 0) !== 1) {
        http_response_code(403);
        view('message', ['title' => 'Acesso negado', 'message' => 'Esta página é só para administradores.', 'user' => $user]);
        exit;
    }
    return $user;
}

/**
 * Links de ativação/redefinição só podem aparecer em respostas (API ou tela) em ambiente de
 * desenvolvimento: APP_ENV=dev ou local. Sem APP_ENV (ou qualquer outro valor) => não expõe.
 */
function expose_account_links(): bool
{
    return AppEnv::isDevWithEnv();
}
