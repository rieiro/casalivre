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

    /**
     * Poda um log de texto sem perder linhas gravadas em paralelo (error_log do PHP,
     * file_put_contents FILE_APPEND), que não usam flock e abrem o arquivo a cada escrita:
     * 1. renomeia o original para um arquivo de trabalho (atômico): escritas novas criam um novo $file;
     * 2. lê o arquivo de trabalho, relendo a cauda até estabilizar (escritas em voo no inode antigo);
     * 3. ANEXA as entradas retidas ao $file atual (O_APPEND, uma entrada por fwrite) — nunca
     *    substitui o arquivo, então nenhuma escrita concorrente é sobrescrita.
     * Consequência: entradas retidas podem aparecer depois de linhas novas gravadas durante a
     * poda (ordem não estritamente cronológica); cada entrada continua íntegra e datada.
     *
     * @return int linhas removidas
     */
    public static function pruneTextLog(string $file, int $cutoff): int
    {
        $work = $file . '.ret-' . bin2hex(random_bytes(4));
        if (!@rename($file, $work)) {
            error_log('CasaLivre: retenção não conseguiu renomear um arquivo de log.');
            return 0;
        }
        $in = @fopen($work, 'rb');
        if ($in === false) {
            error_log('CasaLivre: retenção não conseguiu abrir um arquivo de log.');
            return 0;
        }
        $removed = 0;
        $keep = true; // linhas antes da primeira data: mantém (conservador)
        $entries = [];
        $entry = '';
        $buffer = '';
        $stable = 0;
        while ($stable < 3) {
            $chunk = fread($in, 65536);
            if ($chunk === '' || $chunk === false) {
                usleep(20000);
                $stable++;
                continue;
            }
            $stable = 0;
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos + 1);
                $buffer = substr($buffer, $pos + 1);
                $t = self::lineTime($line);
                if ($t !== null) {
                    if ($entry !== '') {
                        $entries[] = $entry;
                    }
                    $entry = '';
                    $keep = $t >= $cutoff;
                }
                if ($keep) {
                    $entry .= $line;
                } else {
                    $removed++;
                }
            }
        }
        if ($buffer !== '' && $keep) {
            $entry .= $buffer . "\n";
        }
        if ($entry !== '') {
            $entries[] = $entry;
        }
        fclose($in);

        $old = umask(0077);
        $out = @fopen($file, 'ab');
        umask($old);
        if ($out === false) {
            error_log('CasaLivre: retenção não conseguiu reabrir um arquivo de log (conteúdo mantido no arquivo .ret).');
            return 0;
        }
        foreach ($entries as $e) {
            fwrite($out, $e);
        }
        fclose($out);
        @chmod($file, 0600);
        @unlink($work);
        return $removed;
    }
}
