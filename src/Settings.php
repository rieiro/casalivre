<?php

declare(strict_types=1);

if (!class_exists('JsonCache', false)) {
    require_once __DIR__ . '/JsonCache.php';
}

final class Settings
{
    public static function defaults(): array
    {
        return [
            'login_max_failures' => ['5', 'Número máximo de tentativas de login incorretas antes de bloquear a conta.'],
            'login_ip_max_failures' => ['5', 'Número máximo de tentativas de login incorretas a partir do mesmo endereço antes de bloquear o IP.'],
            'login_ip_lock_minutes' => ['15', 'Minutos de bloqueio do endereço após exceder as tentativas de login.'],
            'password_min_length' => ['8', 'Número mínimo de caracteres da senha.'],
            'calendar_months' => ['12', 'Limite global, em meses à frente, do calendário de qualquer imóvel. Cada imóvel começa com 6.'],
            'email_verify_minutes' => ['60', 'Minutos de validade do link de verificação de e-mail.'],
            'password_reset_minutes' => ['30', 'Minutos de validade do link de redefinição de senha.'],
            'max_photos_per_property' => ['40', 'Número máximo de fotos por imóvel.'],
            'session_minutes' => ['60', 'Minutos até a sessão do proprietário expirar.'],
            'results_per_page' => ['20', 'Número de imóveis mostrados por página nos resultados da busca.'],
        ];
    }

    public static function seed(PDO $pdo): void
    {
        $stmt = $pdo->prepare('INSERT INTO settings (skey, value, description) VALUES (?, ?, ?)');
        $exists = $pdo->prepare('SELECT 1 FROM settings WHERE skey = ?');
        foreach (self::defaults() as $key => [$value, $description]) {
            $exists->execute([$key]);
            if (!$exists->fetchColumn()) {
                $stmt->execute([$key, $value, $description]);
            }
        }
        $global = self::defaults()['calendar_months'];
        $pdo->prepare('UPDATE settings SET value = ?, description = ? WHERE skey = ? AND value = ?')
            ->execute([$global[0], $global[1], 'calendar_months', '6']);
        $cached = JsonCache::read('settings.json');
        if (is_array($cached) && (string) ($cached['calendar_months']['value'] ?? '') === '6') {
            $cached['calendar_months']['value'] = $global[0];
            $cached['calendar_months']['description'] = $global[1];
            JsonCache::write('settings.json', $cached);
        }
    }

    public static function get(string $key): string
    {
        $all = self::cached();
        if (isset($all[$key])) {
            return (string) $all[$key]['value'];
        }
        $stmt = db()->prepare('SELECT value, description FROM settings WHERE skey = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            $defaults = self::defaults();
            return $defaults[$key][0] ?? '';
        }
        return (string) $row['value'];
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function refresh(): array
    {
        $rows = self::fromDatabase();
        self::write($rows);
        return $rows;
    }

    private static function cached(): array
    {
        static $rows = null;
        if (is_array($rows)) {
            return $rows;
        }
        $decoded = JsonCache::read('settings.json');
        if (is_array($decoded)) {
            $complete = true;
            foreach (array_keys(self::defaults()) as $key) {
                if (!isset($decoded[$key])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                $rows = $decoded;
                return $rows;
            }
        }
        $rows = self::refresh();
        return $rows;
    }

    private static function fromDatabase(): array
    {
        self::seed(db());
        $out = [];
        foreach (db()->query('SELECT skey, value, description FROM settings ORDER BY skey') as $row) {
            $out[$row['skey']] = ['value' => $row['value'], 'description' => $row['description']];
        }
        return $out;
    }

    private static function write(array $rows): void
    {
        JsonCache::write('settings.json', $rows);
    }
}
