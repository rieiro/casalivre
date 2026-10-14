<?php

declare(strict_types=1);

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
    // Redefinição de senha incrementa users.session_version: sessões abertas antes saem.
    if (!empty($_SESSION['user_id'])
        && (int) ($_SESSION['session_version'] ?? 0) !== UserRepository::sessionVersion((int) $_SESSION['user_id'])) {
        unset($_SESSION['user_id'], $_SESSION['auth_at'], $_SESSION['session_version']);
        $_SESSION['session_expired'] = 1;
    }
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

/**
 * Links de ativação/redefinição só podem aparecer em respostas (API ou tela) em ambiente de
 * desenvolvimento: APP_ENV=dev ou local. Sem APP_ENV (ou qualquer outro valor) => não expõe.
 */
function expose_account_links(): bool
{
    return AppEnv::isDevWithEnv();
}
