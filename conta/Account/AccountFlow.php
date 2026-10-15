<?php

declare(strict_types=1);

require_once __DIR__ . '/Email.php';
require_once __DIR__ . '/UserRepository.php';
require_once dirname(__DIR__) . '/Auth/Password.php';
require_once dirname(__DIR__) . '/Auth/Phone.php';
require_once dirname(__DIR__) . '/Geo/CepRepository.php';

final class AccountFlow
{
    /** Enfileira (tipo, e-mail); token e link são gerados pelo worker (issueLink). @var null|callable(string, string): mixed */
    public static $enqueue = null;

    public static function problem(array $input, bool $requirePassword = true): ?string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $cpf = preg_replace('/\D/', '', (string) ($input['cpf'] ?? ''));
        $email = Email::normalize((string) ($input['email'] ?? ''));
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
            'email' => Email::normalize((string) ($input['email'] ?? '')),
            'password_hash' => $current['password_hash'] ?? PasswordPolicy::hash((string) ($input['password'] ?? '')),
        ];
    }

    public static function register(array $input, int $minutes): array
    {
        $error = self::problem($input, true);
        if ($error !== null) {
            return ['error' => $error];
        }
        // Anti-enumeração: e-mail novo ou existente seguem o mesmo caminho (hash da senha, uma
        // escrita na fila) e devolvem a mesma resposta. Existente recebe só o aviso 'exists'.
        $email = Email::normalize((string) ($input['email'] ?? ''));
        $fields = self::fields($input);
        $fields['status'] = 'pending';
        // INSERT ... ON CONFLICT(email) DO NOTHING: rowCount() 1 = criado; 0 = já existia (inclusive em corrida).
        $created = UserRepository::insertIfAbsent($fields);
        self::notify($created ? 'activation' : 'exists', $email);
        if ($created && function_exists('activity') && class_exists('ActivityLog')) {
            activity(ActivityLog::REGISTER, ['email' => $email]);
        }
        return ['error' => null, 'email' => $email, 'created' => $created, 'expires' => self::until($minutes)];
    }

    /** Uso único atômico: só ativa se o UPDATE condicionado ao hash e à validade afetar exatamente 1 linha. */
    public static function activate(string $token): array
    {
        $user = UserRepository::findByToken('activation_token', $token);
        $ok = $user && ($user['status'] ?? '') === 'pending'
            && UserRepository::consumeActivation((int) $user['id'], UserRepository::tokenHash($token), date('Y-m-d H:i:s'));
        return ['ok' => (bool) $ok, 'email' => $ok ? (string) $user['email'] : null];
    }

    public static function ensureActivation(array $user, int $minutes): array
    {
        $expires = (string) ($user['activation_expires_at'] ?? '');
        $token = (string) ($user['activation_token'] ?? '');
        if ($expires < date('Y-m-d H:i:s') || $token === '') {
            self::notify('activation', (string) $user['email']);
            return ['expires' => self::until($minutes)];
        }
        return ['expires' => $expires];
    }

    public static function checkLogin(string $email, string $password, int $maxFailures): array
    {
        $email = Email::normalize($email);
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
        if (session_status() !== PHP_SESSION_ACTIVE && function_exists('ensure_session')) {
            ensure_session();
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['auth_at'] = time();
        // Guardado no login e conferido a cada requisição (expire_user_session): a redefinição de
        // senha incrementa users.session_version e encerra as outras sessões.
        $_SESSION['session_version'] = UserRepository::sessionVersion($id);
    }

    /** Sempre enfileira e devolve o mesmo formato, exista ou não a conta (tempo de resposta constante). */
    public static function askReset(string $email, int $minutes): array
    {
        $email = Email::normalize($email);
        if ($email !== '') {
            self::notify('reset', $email);
        }
        return ['email' => $email, 'link' => null, 'expires' => self::until($minutes)];
    }

    /**
     * Worker: gera o token bruto, grava só o sha256 e devolve o link ($linkPrefix . token bruto), ou null
     * se não há conta elegível (reset: active; activation: pending).
     */
    public static function issueLink(string $type, string $email, int $minutes, string $linkPrefix): ?string
    {
        $user = UserRepository::findByEmail(Email::normalize($email));
        $need = $type === 'reset' ? 'active' : 'pending';
        if (!$user || ($user['status'] ?? '') !== $need) {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $link = $linkPrefix . $token;
        $expires = self::until($minutes);
        if ($type === 'reset') {
            UserRepository::setReset((int) $user['id'], $token, $expires);
        } else {
            UserRepository::setActivation((int) $user['id'], $token, $expires);
        }
        return $link;
    }

    /**
     * Uso único atômico: senha, limpeza do token e session_version+1 num só UPDATE condicionado ao
     * hash e à validade; só conta como sucesso com rowCount() === 1.
     */
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
        $ok = UserRepository::consumeReset((int) $user['id'], UserRepository::tokenHash($token), date('Y-m-d H:i:s'), PasswordPolicy::hash($password));
        if (!$ok) {
            return ['error' => 'invalid'];
        }
        return ['error' => null, 'email' => (string) $user['email']];
    }

    /**
     * Pedido de troca de e-mail (senha atual já conferida pela ação): guarda o novo como pendente e enfileira
     * o link de confirmação para o endereço NOVO e o aviso para o endereço ATUAL. O e-mail da conta não muda.
     */
    public static function requestEmailChange(int $id, string $currentEmail, string $newEmail): void
    {
        $newEmail = Email::normalize($newEmail);
        UserRepository::setPendingEmail($id, $newEmail);
        self::notify('email_change', $newEmail);
        self::notify('email_change_notice', Email::normalize($currentEmail));
    }

    /**
     * Worker: para cada conta ativa com $newEmail pendente, gera o token bruto, grava só o sha256 com validade
     * e devolve os links ($linkPrefix . token). Lista vazia: nada pendente para esse endereço.
     * @return list<string>
     */
    public static function issueEmailChange(string $newEmail, int $minutes, string $linkPrefix): array
    {
        $links = [];
        foreach (UserRepository::findByPendingEmail(Email::normalize($newEmail)) as $user) {
            $token = bin2hex(random_bytes(16));
            UserRepository::setEmailChange((int) $user['id'], $token, self::until($minutes));
            $links[] = $linkPrefix . $token;
        }
        return $links;
    }

    /** Confirmação (uso único atômico, ver consumeEmailChange). */
    public static function confirmEmailChange(string $token): array
    {
        $user = UserRepository::findByToken('email_change_token', $token);
        if (!$user || ($user['status'] ?? '') !== 'active' || (string) ($user['pending_email'] ?? '') === ''
            || (string) ($user['email_change_expires_at'] ?? '') < date('Y-m-d H:i:s')) {
            return ['ok' => false];
        }
        $ok = UserRepository::consumeEmailChange((int) $user['id'], UserRepository::tokenHash($token), date('Y-m-d H:i:s'));
        return $ok
            ? ['ok' => true, 'id' => (int) $user['id'], 'old' => (string) $user['email'], 'new' => (string) $user['pending_email']]
            : ['ok' => false];
    }

    /** "fu***@dominio" (aviso ao endereço antigo sem expor o novo inteiro). */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, min(2, mb_strlen($local))) . '***@' . $domain;
    }

    private static function until(int $minutes): string
    {
        return (new DateTimeImmutable('now'))->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s');
    }

    private static function notify(string $type, string $email): void
    {
        if (self::$enqueue !== null) {
            (self::$enqueue)($type, $email);
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
