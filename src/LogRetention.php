<?php

declare(strict_types=1);

/**
 * Retenção de logs locais (padrão 14 dias). Rodar diariamente via cron:
 *   15 3 * * *  php /caminho/casalivre/scripts/log-retention.php
 *
 * Cobre:
 * - storage/logs/api/api-*        -> apaga arquivos com mtime anterior ao corte
 * - storage/logs/activity.sqlite  -> DELETE de linhas antigas + VACUUM
 * - MongoDB activity_log (LOG_DRIVER=mongodb) -> deleteMany created_at < corte (sem extensão: ignora e avisa)
 * - storage/error.log, storage/logs/calendar-sync.log, storage/php.log, storage/logs/php.log
 *   -> reescreve mantendo só entradas recentes (linhas sem data herdam a da entrada anterior)
 *
 * Política de 14 dias: PENDENTE de confirmação do Gerente Geral.
 */
final class LogRetention
{
    public const DEFAULT_DAYS = 14;

    /** @return array<string, int|string> resumo por alvo (sem dados de log) */
    public static function run(string $root, int $days = self::DEFAULT_DAYS, ?int $now = null): array
    {
        $now ??= time();
        $cutoff = $now - $days * 86400;
        $report = [];

        $removed = 0;
        foreach (glob($root . '/storage/logs/api/api-*') ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }
        $report['api_arquivos_removidos'] = $removed;

        $sqlite = $root . '/storage/logs/activity.sqlite';
        $report['activity_linhas_removidas'] = is_file($sqlite) ? self::pruneActivity($sqlite, $cutoff) : 0;

        $report['activity_mongo'] = self::pruneMongoFromEnv($cutoff);

        foreach (['storage/error.log', 'storage/logs/calendar-sync.log', 'storage/php.log', 'storage/logs/php.log'] as $rel) {
            $file = $root . '/' . $rel;
            if (is_file($file)) {
                $report[$rel] = self::pruneTextLog($file, $cutoff);
            }
        }
        return $report;
    }

    /** Filtro de documentos antigos no Mongo (created_at é string 'Y-m-d H:i:s', então a comparação lexicográfica vale). */
    public static function mongoFilter(int $cutoff): array
    {
        return ['created_at' => ['$lt' => date('Y-m-d H:i:s', $cutoff)]];
    }

    /**
     * Retenção do activity_log no MongoDB (LOG_DRIVER=mongodb) via deleteMany.
     * Não usamos índice TTL porque created_at é string, e TTL exige campo Date.
     * @return int|string documentos removidos, ou 'nao_configurado' / 'extensao_ausente' / 'erro'
     */
    public static function pruneMongoFromEnv(int $cutoff): int|string
    {
        if ((getenv('LOG_DRIVER') ?: 'sqlite') !== 'mongodb') {
            return 'nao_configurado';
        }
        if (!class_exists(\MongoDB\Driver\Manager::class)) {
            error_log('CasaLivre: retenção Mongo ignorada; extensão mongodb ausente.');
            return 'extensao_ausente';
        }
        try {
            $manager = new \MongoDB\Driver\Manager(getenv('LOG_MONGO_URI') ?: 'mongodb://127.0.0.1:27017');
            $bulk = new \MongoDB\Driver\BulkWrite();
            $bulk->delete(self::mongoFilter($cutoff), ['limit' => 0]);
            $result = $manager->executeBulkWrite((getenv('LOG_MONGO_DB') ?: 'casalivre') . '.activity_log', $bulk);
            return (int) $result->getDeletedCount();
        } catch (Throwable $e) {
            error_log('CasaLivre: retenção Mongo falhou (' . $e::class . ').');
            return 'erro';
        }
    }

    public static function pruneActivity(string $path, int $cutoff): int
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 8000');
        $stmt = $pdo->prepare('DELETE FROM activity_log WHERE created_at < ?');
        $stmt->execute([date('Y-m-d H:i:s', $cutoff)]);
        $count = $stmt->rowCount();
        $pdo->exec('VACUUM');
        return $count;
    }

    /** Timestamp de início de entrada (ISO 8601, "Y-m-d H:i:s" ou "[d-M-Y H:i:s TZ]" do PHP); null se a linha não inicia entrada. */
    public static function lineTime(string $line): ?int
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)?)/', $line, $m)
            || preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Za-z\/_]+)?)\]/', $line, $m)) {
            $t = strtotime($m[1]);
            return $t === false ? null : $t;
        }
        return null;
    }

    /** @return int linhas removidas */
    public static function pruneTextLog(string $file, int $cutoff): int
    {
        $in = @fopen($file, 'rb');
        if ($in === false) {
            error_log('CasaLivre: retenção não conseguiu abrir um arquivo de log.');
            return 0;
        }
        $tmp = $file . '.ret-' . bin2hex(random_bytes(3));
        $old = umask(0077);
        $out = @fopen($tmp, 'wb');
        umask($old);
        if ($out === false) {
            fclose($in);
            error_log('CasaLivre: retenção não conseguiu gravar arquivo temporário.');
            return 0;
        }
        $keep = true; // linhas antes da primeira data: mantém (conservador)
        $removed = 0;
        while (($line = fgets($in)) !== false) {
            $t = self::lineTime($line);
            if ($t !== null) {
                $keep = $t >= $cutoff;
            }
            if ($keep) {
                fwrite($out, $line);
            } else {
                $removed++;
            }
        }
        fclose($in);
        fclose($out);
        if ($removed === 0) {
            @unlink($tmp);
            return 0;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            error_log('CasaLivre: retenção não conseguiu substituir um arquivo de log.');
            return 0;
        }
        @chmod($file, 0600);
        return $removed;
    }
}
