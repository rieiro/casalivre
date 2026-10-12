<?php

declare(strict_types=1);

require_once __DIR__ . '/UserRepository.php';
require_once dirname(__DIR__) . '/Auth/Password.php';
require_once dirname(__DIR__) . '/Auth/Phone.php';
require_once dirname(__DIR__) . '/Geo/CepRepository.php';

final class AccountFlow
{
    /** @var null|callable(string, string): void */
    public static $sendLink = null;

    public static function problem(array $input, bool $requirePassword = true): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $cpf = preg_replace('/\D/', '', (string) ($input['cpf'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'required';
        }
        if (self::place($input) === null) {
            return 'cep';
        }
        if (!PhoneNumber::isValid((string) ($input['phone'] ?? ''))) {
            return 'phone';
        }
        if (strlen($cpf) !== 11) {
            return 'cpf';
        }
        if ($requirePassword) {
            return PasswordPolicy::problem((string) ($input['password'] ?? ''), (string) ($input['password_confirm'] ?? ''));
        }
        return null;
    }

    public static function fields(array $input, ?array $current = null): array
    {
        $place = self::place($input) ?? [
            'address' => '',
            'cep' => '',
            'street' => '',
            'address_number' => '',
            'address_complement' => '',
        ];
        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'cpf' => preg_replace('/\D/', '', (string) ($input['cpf'] ?? '')),
            'address' => $place['address'],
            'cep' => $place['cep'],
            'street' => $place['street'],
            'address_number' => $place['address_number'],
            'address_complement' => $place['address_complement'],
            'phone' => PhoneNumber::digits((string) ($input['phone'] ?? '')),
            'email' => strtolower(trim((string) ($input['email'] ?? ''))),
            'password_hash' => $current['password_hash'] ?? PasswordPolicy::hash((string) ($input['password'] ?? '')),
        ];
    }

    public static function register(array $input, int $minutes, string $linkPrefix): array
    {
        $error = self::problem($input, true);
        if ($error !== null) {
            return ['error' => $error];
        }
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $existing = UserRepository::findByEmail($email);
        if ($existing && in_array(($existing['status'] ?? ''), ['inactive', 'apagado'], true)) {
            return ['error' => ($existing['status'] ?? '') === 'apagado' ? 'deleted' : 'inactive'];
        }
        if ($existing) {
            return ['error' => 'email_taken'];
        }
        $token = bin2hex(random_bytes(16));
        $expires = self::until($minutes);
        $link = $linkPrefix . $token;
        $fields = self::fields($input);
        $fields['status'] = 'pending';
        $fields['activation_token'] = $token;
        $fields['activation_link'] = $link;
        $fields['activation_expires_at'] = $expires;
        $id = UserRepository::insert($fields);
        self::notify($email, $link);
        return ['error' => null, 'id' => $id, 'email' => $email, 'link' => $link, 'expires' => $expires];
    }

    public static function activate(string $token): array
    {
        $user = UserRepository::findByToken('activation_token', $token);
        $ok = $user && ($user['activation_expires_at'] ?? '') >= date('Y-m-d H:i:s') && ($user['status'] ?? '') === 'pending';
        if ($ok) {
            UserRepository::activate((int) $user['id']);
        }
        return ['ok' => (bool) $ok, 'email' => $ok ? (string) $user['email'] : null];
    }

    public static function ensureActivation(array $user, int $minutes, string $linkPrefix): array
    {
        $expires = (string) ($user['activation_expires_at'] ?? '');
        $link = (string) ($user['activation_link'] ?? '');
        if ($expires < date('Y-m-d H:i:s') || $link === '') {
            $token = bin2hex(random_bytes(16));
            $expires = self::until($minutes);
            $link = $linkPrefix . $token;
            UserRepository::setActivation((int) $user['id'], $token, $link, $expires);
            self::notify((string) $user['email'], $link);
        }
        return ['link' => $link, 'expires' => $expires];
    }

    public static function checkLogin(string $email, string $password, int $maxFailures): array
    {
        $email = strtolower(trim($email));
        $user = UserRepository::findByEmail($email);
        if ($user && (int) $user['failed_logins'] >= $maxFailures && ($user['status'] ?? '') === 'active') {
            return ['status' => 'locked', 'email' => $email];
        }
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            if ($user && ($user['status'] ?? '') === 'active') {
                $count = UserRepository::addFailure((int) $user['id']);
                if ($count >= $maxFailures) {
                    return ['status' => 'locked', 'email' => $email, 'failures' => $count];
                }
            }
            return ['status' => 'bad', 'email' => $email];
        }
        $status = (string) ($user['status'] ?? '');
        if ($status === 'apagado') {
            return ['status' => 'deleted', 'email' => $email, 'user' => $user];
        }
        if ($status === 'inactive') {
            return ['status' => 'inactive', 'email' => $email, 'user' => $user];
        }
        if ($status !== 'active') {
            return ['status' => 'pending', 'email' => $email, 'user' => $user];
        }
        return ['status' => 'ok', 'email' => $email, 'user' => $user];
    }

    public static function openSession(int $id): void
    {
        UserRepository::clearFailures($id);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['auth_at'] = time();
    }

    public static function askReset(string $email, int $minutes, string $linkPrefix): ?array
    {
        $email = strtolower(trim($email));
        $user = UserRepository::findByEmail($email);
        if (!$user || ($user['status'] ?? '') !== 'active') {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $expires = self::until($minutes);
        $link = $linkPrefix . $token;
        UserRepository::setReset((int) $user['id'], $token, $link, $expires);
        self::notify($email, $link);
        return ['email' => $email, 'link' => $link, 'expires' => $expires];
    }

    public static function reset(string $token, string $password, string $confirm): array
    {
        $user = UserRepository::findByToken('reset_token', $token);
        if (!$user || ($user['reset_expires_at'] ?? '') < date('Y-m-d H:i:s') || ($user['status'] ?? '') !== 'active') {
            return ['error' => 'invalid'];
        }
        $problem = PasswordPolicy::problem($password, $confirm);
        if ($problem !== null) {
            return ['error' => $problem];
        }
        UserRepository::setPassword((int) $user['id'], PasswordPolicy::hash($password));
        return ['error' => null, 'email' => (string) $user['email']];
    }

    private static function until(int $minutes): string
    {
        return (new DateTimeImmutable('now'))->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s');
    }

    private static function notify(string $email, string $link): void
    {
        if (self::$sendLink !== null) {
            (self::$sendLink)($email, $link);
        }
    }

    private static function place(array $input): ?array
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
        $parts = [];
        foreach ([$street, $number, $complement] as $part) {
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        return [
            'cep' => $cep,
            'street' => $street,
            'address_number' => $number,
            'address_complement' => $complement,
            'address' => implode(', ', $parts),
        ];
    }
}
