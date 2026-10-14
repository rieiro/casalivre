<?php

declare(strict_types=1);

final class UserRepository
{
    public static function insert(array $user): int
    {
        $now = date('Y-m-d H:i:s');
        $status = $user['status'] ?? 'pending';
        $stmt = db()->prepare('INSERT INTO users (
            name, cpf, address, cep, street, address_number, address_complement, phone, email, password_hash, activation_token,
            activation_expires_at, activated_at, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
        $stmt->execute([
            $user['name'],
            $user['cpf'],
            $user['address'],
            $user['cep'] ?? '',
            $user['street'] ?? '',
            $user['address_number'] ?? '',
            $user['address_complement'] ?? '',
            $user['phone'],
            $user['email'],
            $user['password_hash'],
            $user['activation_token'] ?? null,
            $user['activation_expires_at'] ?? null,
            $status === 'active' ? ($user['activated_at'] ?? $now) : null,
            $status,
            $now,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Cadastro sem corrida: INSERT ... ON CONFLICT(email) DO NOTHING. true se criou (rowCount() === 1),
     * false se o e-mail já existia. Sem catch genérico: outros erros de banco sobem.
     */
    public static function insertIfAbsent(array $user): bool
    {
        $now = date('Y-m-d H:i:s');
        $status = $user['status'] ?? 'pending';
        $stmt = db()->prepare('INSERT INTO users (
            name, cpf, address, cep, street, address_number, address_complement, phone, email, password_hash,
            activated_at, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(email) DO NOTHING');
        $stmt->execute([
            $user['name'], $user['cpf'], $user['address'], $user['cep'] ?? '', $user['street'] ?? '',
            $user['address_number'] ?? '', $user['address_complement'] ?? '', $user['phone'], $user['email'],
            $user['password_hash'], $status === 'active' ? $now : null, $status, $now,
        ]);
        return $stmt->rowCount() === 1;
    }

    public static function sessionVersion(int $id): int
    {
        $stmt = db()->prepare('SELECT session_version FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        $stmt->closeCursor();
        return (int) $v;
    }

    /** Ativação atômica (uso único): true só se exatamente 1 linha mudou. */
    public static function consumeActivation(int $id, string $tokenHash, string $now): bool
    {
        $stmt = db()->prepare("UPDATE users SET status = 'active', activated_at = ?, deleted_at = NULL, inactivated_at = NULL, inactivated_ip = NULL,
            activation_token = NULL, activation_expires_at = NULL, failed_logins = 0
            WHERE id = ? AND activation_token = ? AND activation_expires_at >= ? AND status = 'pending'");
        $stmt->execute([$now, $id, $tokenHash, $now]);
        return $stmt->rowCount() === 1;
    }

    /** Redefinição atômica (uso único) + session_version+1 (encerra as outras sessões). */
    public static function consumeReset(int $id, string $tokenHash, string $now, string $passwordHash): bool
    {
        $stmt = db()->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL, failed_logins = 0,
            session_version = session_version + 1
            WHERE id = ? AND reset_token = ? AND reset_expires_at >= ? AND status = 'active'");
        $stmt->execute([$passwordHash, $id, $tokenHash, $now]);
        return $stmt->rowCount() === 1;
    }

    public static function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findActive(int $id): ?array
    {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** sha256 hex do token bruto: só o hash fica no banco; o token bruto vai apenas no e-mail. */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Busca pelo hash do token recebido e confirma com hash_equals(armazenado, sha256(token)). */
    public static function findByToken(string $column, string $token): ?array
    {
        if (!in_array($column, ['activation_token', 'reset_token'], true) || $token === '') {
            return null;
        }
        $given = self::tokenHash($token);
        $stmt = db()->prepare("SELECT * FROM users WHERE $column = ?");
        $stmt->execute([$given]);
        $row = $stmt->fetch();
        return $row && hash_equals((string) $row[$column], $given) ? $row : null;
    }

    public static function update(int $id, array $user): array
    {
        return self::write($id, [
            'name' => $user['name'],
            'cpf' => $user['cpf'],
            'address' => $user['address'],
            'cep' => $user['cep'] ?? '',
            'street' => $user['street'] ?? '',
            'address_number' => $user['address_number'] ?? '',
            'address_complement' => $user['address_complement'] ?? '',
            'phone' => $user['phone'],
            'email' => $user['email'],
        ], ['name', 'cpf', 'address', 'cep', 'street', 'address_number', 'address_complement', 'phone', 'email']);
    }

    public static function deactivate(int $id, string $ip): array
    {
        $now = date('Y-m-d H:i:s');
        return self::write($id, [
            'status' => 'apagado',
            'deleted_at' => $now,
            'inactivated_at' => $now,
            'inactivated_ip' => $ip,
        ], ['status', 'deleted_at', 'inactivated_at', 'inactivated_ip']);
    }

    public static function activate(int $id): void
    {
        db()->prepare("UPDATE users SET status = 'active', activated_at = ?, deleted_at = NULL, inactivated_at = NULL, inactivated_ip = NULL, activation_token = NULL, activation_expires_at = NULL, failed_logins = 0 WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $id]);
    }

    public static function addFailure(int $id): int
    {
        db()->prepare('UPDATE users SET failed_logins = failed_logins + 1 WHERE id = ?')->execute([$id]);
        $stmt = db()->prepare('SELECT failed_logins FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    public static function clearFailures(int $id): void
    {
        db()->prepare('UPDATE users SET failed_logins = 0 WHERE id = ?')->execute([$id]);
    }

    /** Grava só o hash do token bruto (sha256). */
    public static function setActivation(int $id, string $rawToken, string $expires): void
    {
        db()->prepare('UPDATE users SET activation_token = ?, activation_expires_at = ? WHERE id = ?')
            ->execute([self::tokenHash($rawToken), $expires, $id]);
    }

    /** Grava só o hash do token bruto (sha256). */
    public static function setReset(int $id, string $rawToken, string $expires): void
    {
        db()->prepare('UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE id = ?')
            ->execute([self::tokenHash($rawToken), $expires, $id]);
    }

    public static function setPassword(int $id, string $hash): void
    {
        db()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL, failed_logins = 0, session_version = session_version + 1 WHERE id = ?')
            ->execute([$hash, $id]);
    }

    private static function write(int $id, array $values, array $columns): array
    {
        $driver = (string) db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $jsonFn = $driver === 'pgsql' ? 'json_build_object' : 'json_object';
        $jsonArgs = [];
        foreach ($columns as $column) {
            self::column($column);
            $jsonArgs[] = "'" . $column . "', " . self::q($column);
        }
        $set = [self::q('audit_hold') . ' = ' . $jsonFn . '(' . implode(', ', $jsonArgs) . ')'];
        $params = [];
        foreach ($values as $column => $value) {
            self::column($column);
            $set[] = self::q($column) . ' = ?';
            $params[] = $value;
        }
        $returning = [self::q('audit_hold')];
        foreach ($columns as $column) {
            $returning[] = self::q($column);
        }
        $sql = 'UPDATE "users" SET ' . implode(', ', $set) . ' WHERE id = ? RETURNING ' . implode(', ', $returning);
        $stmt = db()->prepare($sql);
        $stmt->execute([...$params, $id]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $old = json_decode((string) ($row['audit_hold'] ?? ''), true);
            $new = [];
            foreach ($columns as $column) {
                $new[$column] = $row[$column] ?? null;
            }
            $out[] = ['old' => is_array($old) ? $old : [], 'new' => $new];
        }
        return $out;
    }

    private static function column(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Coluna inválida.');
        }
    }

    private static function q(string $name): string
    {
        return '"' . $name . '"';
    }
}
