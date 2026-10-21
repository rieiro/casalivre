<?php

declare(strict_types=1);

require_once __DIR__ . '/LogPrivacy.php';

/**
 * Retenção de logs locais em arquivo (padrão 14 dias). Rodar diariamente via cron:
 *   15 3 * * *  php /caminho/casalivre/scripts/log-retention.php
 *
 * Cobre:
 * - storage/logs/api/api-*        -> apaga arquivos com mtime anterior ao corte
 * - storage/error.log, storage/logs/calendar-sync.log, storage/php.log, storage/logs/php.log
 *   -> reescreve mantendo só entradas recentes (linhas sem data herdam a da entrada anterior)
 *
 * O log de atividades está no PostgreSQL (audit.activity_log): partições diárias removidas por DROP pela função
 * DEFINER audit.activity_retention_step (ver pgStep() e scripts/log-retention.php), nunca DELETE + VACUUM.
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

        foreach (['storage/error.log', 'storage/logs/calendar-sync.log', 'storage/php.log', 'storage/logs/php.log'] as $rel) {
            $file = $root . '/' . $rel;
            if (is_file($file)) {
                $report[$rel] = self::pruneTextLog($file, $cutoff);
            }
        }
        return $report;
    }

    /**
     * PG activity-log retention (spec §6), as a login with casalivre_log_job (SET ROLE): creates the partitions
     * (activity: today..+7 days; audit: this and next year), then drops one eligible leaf per call until none is
     * left. $heldRefs: actor_ref of every open user hold (heldRefs()). Dry-run by default: the first real DROP in
     * production needs Rafael's approval.
     * @return array<string, int|string>
     */
    public static function pgStep(PDO $pdo, array $heldRefs, bool $dryRun = true): array
    {
        $pdo->exec('SET ROLE casalivre_log_job');
        try {
            $report = [
                'activity_particoes_criadas' => (int) $pdo->query('SELECT audit.activity_partitions_ensure(7)')->fetchColumn(),
                'audit_particoes_criadas' => (int) $pdo->query('SELECT audit.audit_partitions_ensure()')->fetchColumn(),
            ];
            $refs = '{' . implode(',', array_map(static fn (string $r): string => '"\\\\x' . bin2hex($r) . '"', $heldRefs)) . '}';
            $step = $pdo->prepare('SELECT leaf, held, dropped FROM audit.activity_retention_step(?::bytea[], ?)');
            $dropped = [];
            $held = 0;
            for ($i = 0; $i < 1000; $i++) {
                $step->bindValue(1, $refs);
                $step->bindValue(2, $dryRun, PDO::PARAM_BOOL);
                $step->execute();
                $row = $step->fetch(PDO::FETCH_ASSOC);
                $step->closeCursor();
                if (!is_array($row) || $row['leaf'] === null) {
                    break;
                }
                $dropped[] = (string) $row['leaf'];
                $held += (int) $row['held'];
                if ($dryRun) {
                    break; // dry-run only reports the next leaf
                }
            }
            $report[$dryRun ? 'activity_proxima_particao' : 'activity_particoes_removidas'] = $dryRun ? ($dropped[0] ?? '-') : count($dropped);
            $report['activity_linhas_retidas'] = $held;
            return $report;
        } finally {
            $pdo->exec('RESET ROLE');
        }
    }

    /**
     * actor_ref (32 bytes) of every open legal hold on a user, for the current LOG_SALT version (other active
     * versions come with the key files of PR-LOG-2). Without LOG_SALT: throws, so the step never runs with no refs.
     * @return list<string>
     */
    public static function heldRefs(PDO $pdo): array
    {
        $ids = $pdo->query("SELECT DISTINCT subject_id FROM legal_holds WHERE subject_type = 'user' AND closed_at IS NULL ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        if ($ids !== [] && LogPrivacy::salt() === null) {
            throw new RuntimeException('LOG_SALT missing: cannot compute the actor_ref of open legal holds.');
        }
        return array_values(array_map(static fn ($id): string => (string) LogPrivacy::userRef((int) $id), $ids));
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
