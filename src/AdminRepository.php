<?php

declare(strict_types=1);

require_once __DIR__ . "/Db.php";

final class AdminRepository
{
    public const ACTION_LABELS_EN = [
        1 => 'Successful login',
        2 => 'Failed login attempt',
        3 => 'User logout',
        4 => 'Owner account registration',
        5 => 'Property search query',
        6 => 'Account profile update',
        7 => 'Account marked for deletion',
        8 => 'Property listing created',
        9 => 'Property listing updated',
        10 => 'Property listing inactivated',
        11 => 'Photo batch uploaded',
        12 => 'Photo deleted',
        13 => 'Calendar dates updated',
        14 => 'Calendar dates imported',
        15 => 'iCal calendar exported',
        16 => 'Guest review submitted',
        17 => 'Property detail view',
        18 => 'Account activation verified',
        19 => 'Password reset requested',
        20 => 'Password reset confirmed',
        21 => 'Login rate limit locked',
        22 => 'Interface locale changed',
        23 => 'Nightly price updated',
        24 => 'Calendar nights blocked',
        25 => 'Calendar nights unblocked',
        26 => 'iCal sync feed added',
        27 => 'iCal sync feed removed',
        28 => 'Minimum stay nights changed',
        29 => 'iCal feed synchronized',
        30 => 'Internal system error',
        31 => 'Cover photo updated',
        32 => 'Platform visual theme changed',
        33 => 'Group created by admin',
        34 => 'Group settings updated',
        35 => 'Group domain added',
        36 => 'Group domain removed',
        37 => 'Group member role assigned',
        38 => 'Group member removed',
        39 => 'Group property invite created',
        40 => 'Group property invite cancelled',
        41 => 'Group property invite accepted',
        42 => 'Group property invite rejected',
        43 => 'Group property removed',
        44 => 'Group self-registered',
        45 => 'Group domain DNS verified',
        46 => 'Group domain verification lost',
        47 => 'Audit log viewed',
        48 => 'Valkey cache unavailable',
        49 => 'Stuck processing photo discarded',
        50 => 'Calendar feed failed permanently',
        51 => 'Booking quote created',
        52 => 'Booking reservation confirmed',
    ];
    /** @return array<string, mixed> */
    public static function getKpis(): array
    {
        $pdo = db();

        // 1. Properties
        $pStmt = $pdo->query("SELECT count(*) as total,
            count(*) FILTER (WHERE status = 'active') as active,
            count(*) FILTER (WHERE status != 'active') as inactive
            FROM properties");
        $propRow = $pStmt ? $pStmt->fetch(PDO::FETCH_ASSOC) : [];

        // 2. Users
        $uStmt = $pdo->query("SELECT count(*) as total,
            count(*) FILTER (WHERE is_admin = 1) as admins,
            count(*) FILTER (WHERE failed_logins >= 5) as locked
            FROM users");
        $userRow = $uStmt ? $uStmt->fetch(PDO::FETCH_ASSOC) : [];

        // 3. Feeds
        $fStmt = $pdo->query("SELECT count(*) as total,
            count(*) FILTER (WHERE last_status = 'OK') as ok,
            count(*) FILTER (WHERE last_status = 'NOK') as errors,
            count(*) FILTER (WHERE next_sync_at <= now() OR last_status = 'PENDENTE') as pending,
            count(*) FILTER (WHERE next_sync_at = 'infinity') as stopped
            FROM calendar_feeds");
        $feedRow = $fStmt ? $fStmt->fetch(PDO::FETCH_ASSOC) : [];

        // 4. Queues
        $imgRow = ["total" => 0, "pending" => 0, "failed" => 0];
        $mailRow = ["total" => 0, "pending" => 0, "failed" => 0];
        try {
            $iq = $pdo->query("SELECT count(*) as total,
                count(*) FILTER (WHERE status = 'pending') as pending,
                count(*) FILTER (WHERE status = 'failed') as failed
                FROM image_queue");
            if ($iq) {
                $imgRow = $iq->fetch(PDO::FETCH_ASSOC) ?: $imgRow;
            }
        } catch (Throwable) {
        }
        try {
            $mq = $pdo->query("SELECT count(*) as total,
                count(*) FILTER (WHERE status = 'pending') as pending,
                count(*) FILTER (WHERE status = 'failed') as failed
                FROM mail_queue");
            if ($mq) {
                $mailRow = $mq->fetch(PDO::FETCH_ASSOC) ?: $mailRow;
            }
        } catch (Throwable) {
        }

        // 5. Quotes
        $quotesRow = ["total" => 0, "pending" => 0, "confirmed" => 0];
        try {
            $qq = $pdo->query("SELECT count(*) as total,
                count(*) FILTER (WHERE status = 'pending') as pending,
                count(*) FILTER (WHERE status = 'confirmed') as confirmed
                FROM quotes");
            if ($qq) {
                $quotesRow = $qq->fetch(PDO::FETCH_ASSOC) ?: $quotesRow;
            }
        } catch (Throwable) {
        }

        return [
            "properties" => [
                "total" => (int) ($propRow["total"] ?? 0),
                "active" => (int) ($propRow["active"] ?? 0),
                "inactive" => (int) ($propRow["inactive"] ?? 0),
            ],
            "users" => [
                "total" => (int) ($userRow["total"] ?? 0),
                "admins" => (int) ($userRow["admins"] ?? 0),
                "locked" => (int) ($userRow["locked"] ?? 0),
            ],
            "feeds" => [
                "total" => (int) ($feedRow["total"] ?? 0),
                "ok" => (int) ($feedRow["ok"] ?? 0),
                "errors" => (int) ($feedRow["errors"] ?? 0),
                "pending" => (int) ($feedRow["pending"] ?? 0),
                "stopped" => (int) ($feedRow["stopped"] ?? 0),
            ],
            "queues" => [
                "image_queue" => [
                    "total" => (int) ($imgRow["total"] ?? 0),
                    "pending" => (int) ($imgRow["pending"] ?? 0),
                    "failed" => (int) ($imgRow["failed"] ?? 0),
                ],
                "mail_queue" => [
                    "total" => (int) ($mailRow["total"] ?? 0),
                    "pending" => (int) ($mailRow["pending"] ?? 0),
                    "failed" => (int) ($mailRow["failed"] ?? 0),
                ],
            ],
            "quotes" => [
                "total" => (int) ($quotesRow["total"] ?? 0),
                "pending" => (int) ($quotesRow["pending"] ?? 0),
                "confirmed" => (int) ($quotesRow["confirmed"] ?? 0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function getEnvironmentDiagnostic(): array
    {
        $pdo = db();
        $pgVer = "";
        try {
            $pgVer = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
        }

        $webp = function_exists("imagewebp") && (gd_info()["WebP Support"] ?? false);
        $opcache = function_exists("opcache_get_status") && is_array(opcache_get_status(false));

        $freeBytes = @disk_free_space("/") ?: 0;
        $totalBytes = @disk_total_space("/") ?: 0;

        return [
            "php_version" => PHP_VERSION,
            "php_os" => PHP_OS,
            "postgres_version" => $pgVer,
            "webp_support" => $webp,
            "opcache_enabled" => $opcache,
            "disk_free_gb" => round($freeBytes / (1024 * 1024 * 1024), 2),
            "disk_total_gb" => round($totalBytes / (1024 * 1024 * 1024), 2),
            "disk_used_percent" => $totalBytes > 0 ? round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1) : 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function getRecentActivity(int $limit = 10): array
    {
        $rows = [];
        try {
            $ops = Db::connectAs("OPS_DB_USER", "OPS_DB_PASSWORD");
            $ops->exec("SET ROLE casalivre_ops_logread");
            $stmt = $ops->prepare("SELECT id, created_at, code, retention_class, actor_kind, payload, before_value, after_value FROM audit.activity_recent(NULL, ?)");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        try {
            $descriptions = db()->query("SELECT code, description FROM activity_actions")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            foreach ($rows as &$r) {
                $code = (int) ($r["code"] ?? 0);
                $r["action_label"] = self::ACTION_LABELS_EN[$code] ?? ("Action #" . $code);
            }
            unset($r);
        } catch (Throwable) {
        }

        return $rows;
    }

    public static function getRunitServices(): array
    {
        $services = [
            "casalivre-queue-worker" => false,
            "nginx" => false,
            "php-fpm8.4" => false,
            "postgresql18" => false,
            "casalivre-valkey" => false,
        ];

        // 1. Process table scan (/proc)
        $pids = glob('/proc/[0-9]*/cmdline') ?: [];
        $lines = [];
        foreach ($pids as $p) {
            $c = @file_get_contents($p);
            if ($c) {
                $lines[] = str_replace(chr(0), ' ', $c);
            }
        }
        $procCmds = implode(' ', $lines);

        if (str_contains($procCmds, 'queue-worker.php') || str_contains($procCmds, 'runsv casalivre-queue-worker')) {
            $services['casalivre-queue-worker'] = true;
        }
        if (str_contains($procCmds, 'nginx: master') || str_contains($procCmds, 'nginx: worker') || str_contains($procCmds, 'runsv nginx')) {
            $services['nginx'] = true;
        }
        if (str_contains($procCmds, 'php-fpm: master') || str_contains($procCmds, 'php-fpm: pool') || str_contains($procCmds, 'runsv php-fpm')) {
            $services['php-fpm8.4'] = true;
        }
        if (str_contains($procCmds, 'postgres') || str_contains($procCmds, 'runsv postgresql18')) {
            $services['postgresql18'] = true;
        }
        if (str_contains($procCmds, 'valkey-server') || str_contains($procCmds, 'runsv casalivre-valkey')) {
            $services['casalivre-valkey'] = true;
        }

        // 2. Functional & socket fallback checks
        if (!$services['postgresql18']) {
            try {
                $st = db()->query('SELECT 1');
                if ($st && (int) $st->fetchColumn() === 1) {
                    $services['postgresql18'] = true;
                }
            } catch (Throwable) {
            }
        }

        if (!$services['casalivre-valkey'] && (file_exists('/run/casalivre-valkey/valkey.sock') || file_exists('/tmp/valkey.sock'))) {
            $services['casalivre-valkey'] = true;
        }

        if (!$services['php-fpm8.4'] && (file_exists('/run/php-fpm-casalivre.sock') || PHP_SAPI === 'fpm-fcgi')) {
            $services['php-fpm8.4'] = true;
        }

        // 3. Status file check (if permissions allow)
        foreach (array_keys($services) as $svc) {
            if ($services[$svc]) {
                continue;
            }
            $statusFile = "/var/service/" . $svc . "/supervise/status";
            if (is_file($statusFile) && is_readable($statusFile)) {
                $data = @file_get_contents($statusFile);
                if (is_string($data) && strlen($data) >= 18) {
                    $state = ord($data[17] ?? " ");
                    $services[$svc] = ($state === 1);
                }
            }
        }

        return $services;
    }

    /** @return list<array<string, mixed>> */
    public static function getSecurityIps(int $limit = 50): array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT ip, failures, locked_until FROM login_rate_limit ORDER BY failures DESC, locked_until DESC NULLS LAST LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $now = date('Y-m-d H:i:s');
        $maxAttempts = class_exists('LoginRateLimiter') ? LoginRateLimiter::maxAttempts() : 5;

        foreach ($rows as &$row) {
            $lockedUntil = (string) ($row['locked_until'] ?? '');
            $failures = (int) ($row['failures'] ?? 0);
            $row['is_locked'] = ($failures >= $maxAttempts) || ($lockedUntil !== '' && $lockedUntil > $now);
        }
        unset($row);

        return $rows;
    }

    public static function unblockIp(string $ip): void
    {
        if (class_exists('LoginRateLimiter')) {
            LoginRateLimiter::clear($ip);
        } else {
            db()->prepare('DELETE FROM login_rate_limit WHERE ip = ?')->execute([$ip]);
        }
    }

    public static function blockIp(string $ip, int $minutes = 60): void
    {
        self::unblockIp($ip);
        $lockUntil = date('Y-m-d H:i:s', time() + max(1, $minutes) * 60);
        db()->prepare('INSERT INTO login_rate_limit (ip, failures, locked_until) VALUES (?, 999, ?)')->execute([$ip, $lockUntil]);
    }

    /** @return list<array<string, mixed>> */
    public static function getAuditLogs(?int $code = null, ?string $retentionClass = null, int $limit = 100): array
    {
        $rows = [];
        try {
            $ops = Db::connectAs('OPS_DB_USER', 'OPS_DB_PASSWORD');
            $ops->exec('SET ROLE casalivre_ops_logread');
            $stmt = $ops->prepare('SELECT id, created_at, code, retention_class, subtype, actor_kind, payload, before_value, after_value FROM audit.activity_recent(?, ?)');
            $stmt->bindValue(1, $code, $code !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(2, max(1, min(500, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        try {
            $descriptions = db()->query('SELECT code, description FROM activity_actions')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            foreach ($rows as &$r) {
                $c = (int) ($r['code'] ?? 0);
                $r['action_label'] = self::ACTION_LABELS_EN[$c] ?? ('Action #' . $c);
            }
            unset($r);
        } catch (Throwable) {
        }

        if ($retentionClass !== null && $retentionClass !== '') {
            $rows = array_values(array_filter($rows, static fn ($row): bool => ($row['retention_class'] ?? '') === $retentionClass));
        }

        return $rows;
    }

    /** @return array<string, int> */
    public static function getAuditMetrics(): array
    {
        $recent = self::getAuditLogs(null, null, 500);
        $total = count($recent);
        $security = 0;
        $admin = 0;
        $other = 0;
        foreach ($recent as $r) {
            match ($r['retention_class'] ?? '') {
                'security' => $security++,
                'admin' => $admin++,
                default => $other++,
            };
        }
        return [
            'total' => $total,
            'security' => $security,
            'admin' => $admin,
            'other' => $other,
        ];
    }

    /** @return array<string, mixed> */
    public static function getQueueDetails(): array
    {
        $pdo = db();
        $imgStats = ['total' => 0, 'pending' => 0, 'processing' => 0, 'failed' => 0];
        $imgRecent = [];
        try {
            $st = $pdo->query("SELECT count(*) as total,
                count(*) FILTER (WHERE status = 'pending') as pending,
                count(*) FILTER (WHERE status = 'processing') as processing,
                count(*) FILTER (WHERE status = 'failed') as failed
                FROM image_queue");
            if ($st) {
                $imgStats = $st->fetch(PDO::FETCH_ASSOC) ?: $imgStats;
            }
            $rSt = $pdo->query("SELECT id, photo_id, status, attempts, last_error, created_at FROM image_queue ORDER BY id DESC LIMIT 10");
            $imgRecent = $rSt ? ($rSt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
        }

        $mailStats = ['total' => 0, 'pending' => 0, 'failed' => 0];
        $mailRecent = [];
        try {
            $mst = $pdo->query("SELECT count(*) as total,
                count(*) FILTER (WHERE status = 'pending') as pending,
                count(*) FILTER (WHERE status = 'failed') as failed
                FROM mail_queue");
            if ($mst) {
                $mailStats = $mst->fetch(PDO::FETCH_ASSOC) ?: $mailStats;
            }
            $mrSt = $pdo->query("SELECT id, type, recipient, status, attempts, last_error, created_at FROM mail_queue ORDER BY id DESC LIMIT 10");
            $mailRecent = $mrSt ? ($mrSt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
        }

        return [
            'image_queue' => [
                'stats' => $imgStats,
                'recent' => $imgRecent,
            ],
            'mail_queue' => [
                'stats' => $mailStats,
                'recent' => $mailRecent,
            ],
            'services' => self::getRunitServices(),
        ];
    }

    public static function retryFailedQueueItems(string $queue): int
    {
        $table = match ($queue) {
            'image_queue' => 'image_queue',
            'mail_queue' => 'mail_queue',
            default => null,
        };
        if ($table === null) {
            return 0;
        }
        $stmt = db()->prepare("UPDATE {$table} SET status = 'pending', attempts = 0, last_error = NULL WHERE status = 'failed'");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** @return array<string, array<string, string>> */
    public static function getTranslations(?string $search = null, ?string $prefix = null): array
    {
        $pdo = db();
        $sql = "SELECT lang, tkey, text FROM translations";
        $where = [];
        $params = [];

        if ($prefix !== null && $prefix !== '') {
            $where[] = "(tkey = ? OR tkey LIKE ?)";
            $params[] = $prefix;
            $params[] = $prefix . '.%';
        }
        if ($search !== null && $search !== '') {
            $where[] = "(tkey ILIKE ? OR text ILIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY tkey, lang";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        foreach ($rows as $r) {
            $key = (string) ($r['tkey'] ?? '');
            $lang = (string) ($r['lang'] ?? '');
            $text = (string) ($r['text'] ?? '');
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['pt-BR' => '', 'en' => '', 'es' => ''];
            }
            $grouped[$key][$lang] = $text;
        }

        return $grouped;
    }

    public static function updateTranslation(string $tkey, string $lang, string $text): void
    {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO translations (lang, tkey, text) VALUES (?, ?, ?)
            ON CONFLICT (lang, tkey) DO UPDATE SET text = EXCLUDED.text");
        $stmt->execute([$lang, $tkey, $text]);
        if (class_exists('I18n')) {
            I18n::refresh();
        }
    }
}
