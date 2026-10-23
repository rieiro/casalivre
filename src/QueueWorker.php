<?php

declare(strict_types=1);

require_once __DIR__ . '/JobQueue.php';
require_once __DIR__ . '/Db.php';

/**
 * Lote genérico de worker por cron: lock exclusivo (arquivo 0600, flock sem espera), teto de tempo
 * abaixo de STALE_SECONDS, ack/fail com backoff e purga de itens antigos. Erro de conexão com o banco (Db::isConnectionError)
 * não conta como falha do item: o lote para e a exceção sobe (o worker de longa duração sai e o runit o reinicia).
 */
final class QueueWorker
{
    public const MAX_RUN_SECONDS = SqlJobQueue::STALE_SECONDS - 120;

    /** Abre o lock com permissão 0600. @return resource */
    public static function openLock(string $lockFile)
    {
        $old = umask(0077);
        try {
            $lock = fopen($lockFile, 'c');
        } finally {
            umask($old);
        }
        if ($lock === false) {
            throw new RuntimeException('Não foi possível abrir o lock do worker.');
        }
        @chmod($lockFile, 0600);
        return $lock;
    }

    /**
     * Processa um lote. $handle(job) devolve true (feito) ou false (ignorado) e lança em falha;
     * $onGiveUp(job, Throwable) roda quando a fila desiste do item; $label prefixa a linha de log (sem dados pessoais). Devolve null se outro worker tem o lock;
     * senão ['enviados' => n, 'ignorados' => n, 'falhas' => n, 'desistencias' => n, 'purgados' => n].
     *
     * @param callable(array): bool $handle
     * @param null|callable(array, Throwable): void $onGiveUp
     */
    public static function run(JobQueue $queue, string $lockFile, callable $handle, int $batch, int $retentionDays, int $maxSeconds = self::MAX_RUN_SECONDS, ?callable $onGiveUp = null, string $label = 'queue: item'): ?array
    {
        $lock = self::openLock($lockFile);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            $report = ['enviados' => 0, 'ignorados' => 0, 'falhas' => 0, 'desistencias' => 0, 'purgados' => 0];
            $deadline = microtime(true) + min($maxSeconds, self::MAX_RUN_SECONDS);
            for ($i = 0; $i < $batch && microtime(true) < $deadline; $i++) {
                $jobs = $queue->reserve(1);
                if ($jobs === []) {
                    break;
                }
                $job = $jobs[0];
                try {
                    $done = $handle($job);
                    $queue->ack($job['id']);
                    $report[$done ? 'enviados' : 'ignorados']++;
                } catch (Throwable $e) {
                    if (Db::isConnectionError($e)) {
                        // DB connection lost: not the job's fault, so no fail()/attempt. The job stays reserved and
                        // goes back to the queue as a stale reservation; the worker exits (scripts/queue-worker.php).
                        throw $e;
                    }
                    $report['falhas']++;
                    if ($queue->fail($job['id'], $e::class)) {
                        $report['desistencias']++;
                        error_log($label . ' #' . $job['id'] . ' desistido após ' . Queues::MAX_ATTEMPTS . ' tentativas (' . $e::class . ')');
                        if ($onGiveUp !== null) {
                            $onGiveUp($job, $e);
                        }
                    }
                }
            }
            $report['purgados'] = $queue->purge($retentionDays);
            flock($lock, LOCK_UN);
            return $report;
        } finally {
            fclose($lock);
        }
    }
}
