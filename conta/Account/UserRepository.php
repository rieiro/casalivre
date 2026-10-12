<?php

declare(strict_types=1);

final class UserRepository
{
    public static function insert(array $user): int
    {
        $now = date('Y-m-d H:i:s');
        $status = $user['status'] ?? 'pending';
        $stmt = db()->prepare('INSERT INTO users (
            name, cpf, address, cep, street, address_number, address_complement, phone, email, password_hash, activation_token, activation_link,
            activation_expires_at, activated_at, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
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
            $user['activation_link'] ?? null,
            $user['activation_expires_at'] ?? null,
            $status === 'active' ? ($user['activated_at'] ?? $now) : null,
            $status,
            $now,
        ]);
        return (int) $stmt->fetchColumn();
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

    public static function findByToken(string $column, string $token): ?array
    {
        if (!in_array($column, ['activation_token', 'reset_token'], true)) {
            return null;
        }
        $stmt = db()->prepare("SELECT * FROM users WHERE $column = ?");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
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
        db()->prepare("UPDATE users SET status = 'active', activated_at = ?, deleted_at = NULL, inactivated_at = NULL, inactivated_ip = NULL, activation_token = NULL, activation_link = NULL, activation_expires_at = NULL, failed_logins = 0 WHERE id = ?")
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

    public static function setActivation(int $id, string $token, string $link, string $expires): void
    {
        db()->prepare('UPDATE users SET activation_token = ?, activation_link = ?, activation_expires_at = ? WHERE id = ?')
            ->execute([$token, $link, $expires, $id]);
    }

    public static function setReset(int $id, string $token, string $link, string $expires): void
    {
        db()->prepare('UPDATE users SET reset_token = ?, reset_link = ?, reset_expires_at = ? WHERE id = ?')
            ->execute([$token, $link, $expires, $id]);
    }

    public static function setPassword(int $id, string $hash): void
    {
        db()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_link = NULL, reset_expires_at = NULL, failed_logins = 0 WHERE id = ?')
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
