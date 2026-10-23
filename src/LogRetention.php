<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
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
 * O log de atividades está no PostgreSQL (audit.activity_log): partições criadas uma por transação (leaf_ensure, que
 * também esvazia a DEFAULT) e removidas por DROP pela função DEFINER audit.activity_retention_step (ver pgStep() e
 * scripts/log-retention.php), nunca DELETE + VACUUM.
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

    /** Lead of daily activity_log leaves (today..today+N, UTC). */
    public const LEAD_DAYS = 14;

    /**
     * PG log retention (spec §6, BD verdict db @07c428b), as a login with casalivre_log_job (SET ROLE), in autocommit:
     * 1. audit.activity_leaves_plan(14) + audit.audit_leaves_plan(): missing leaves and every day/year with rows in
     *    a DEFAULT; one *_leaf_ensure call per row, so one leaf per transaction. A leaf that fails (lock_timeout
     *    behind a long reader, ...) is logged 'failed' in purge_runs by the function and retried next run; the others
     *    are still created. Rows found in a DEFAULT are moved into the new leaf.
     * 2. activity_retention_step until nothing is left (dry-run: only the next leaf). $heldRefs: actor_ref of every
     *    open user hold (heldRefs()). The first real DROP in production needs Rafael's approval.
     * 3. audit.log_partitions_health(): every check in the report; 'saude_criticos' counts the critical ones
     *    (scripts/log-retention.php exits non-zero and alerts on any).
     * @return array<string, int|string>
     */
    public static function pgStep(PDO $pdo, array $heldRefs, bool $dryRun = true): array
    {
        if ($pdo->inTransaction()) {
            throw new LogicException('pgStep needs autocommit: one leaf per transaction');
        }
        $pdo->exec('SET ROLE casalivre_log_job');
        try {
            $report = [];
            $plans = [
                'activity' => ['SELECT class, day::text AS unit FROM audit.activity_leaves_plan(' . self::LEAD_DAYS . ')', 'SELECT audit.activity_leaf_ensure(?, ?::date)'],
                'audit' => ['SELECT class, year::text AS unit FROM audit.audit_leaves_plan()', 'SELECT audit.audit_leaf_ensure(?, ?::int)'],
            ];
            foreach ($plans as $kind => [$planSql, $ensureSql]) {
                $created = $moved = 0;
                $failed = [];
                $ensure = $pdo->prepare($ensureSql);
                foreach ($pdo->query($planSql)->fetchAll() as $leaf) {
                    $ensure->execute([$leaf['class'], $leaf['unit']]);
                    $result = (string) $ensure->fetchColumn();
                    $ensure->closeCursor();
                    if ($result === 'created') {
                        $created++;
                    } elseif (str_starts_with($result, 'moved ')) {
                        $created++;
                        $moved += (int) substr($result, 6);
                    } elseif ($result === 'failed') {
                        $failed[] = $leaf['class'] . ' ' . $leaf['unit'];
                    }
                }
                $report[$kind . '_particoes_criadas'] = $created;
                $report[$kind . '_linhas_movidas_da_default'] = $moved;
                if ($failed !== []) {
                    $report['aviso_' . $kind . '_particoes_falharam'] = implode(', ', $failed) . ' (purge_runs status failed; o próximo run tenta de novo)';
                    error_log('CasaLivre WARN: log retention: ' . count($failed) . ' ' . $kind . ' leaf(s) failed (see purge_runs); the others were created.');
                }
            }
            $refs = Db::byteaArrayLiteral($heldRefs);
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
            $critical = 0;
            foreach ($pdo->query('SELECT check_name, status, detail FROM audit.log_partitions_health()')->fetchAll() as $h) {
                $report['saude ' . $h['check_name']] = trim($h['status'] . ' ' . $h['detail']);
                $critical += $h['status'] === 'critical' ? 1 : 0;
            }
            $report['saude_criticos'] = $critical;
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
