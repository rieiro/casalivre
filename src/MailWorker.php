<?php

declare(strict_types=1);

require_once __DIR__ . '/MailQueue.php';
require_once __DIR__ . '/Mailer.php';

/**
 * Um lote do worker de e-mail: lock exclusivo (arquivo 0600), entrega via Mailer::deliverAccountMail(),
 * ack/fail e purga de sent/failed antigos. O lote para em MAX_RUN_SECONDS (abaixo de STALE_SECONDS).
 * Logs nunca levam endereço nem link.
 */
final class MailWorker
{
    public const DEFAULT_BATCH = 50;
    public const DEFAULT_RETENTION_DAYS = 7;
    public const MAX_RUN_SECONDS = SqliteMailQueue::STALE_SECONDS - 120;

    /** Entrega (troca em testes); padrão Mailer::deliverAccountMail. Devolve false se nada foi enviado. @var null|callable(string, string): bool */
    public static $deliver = null;

    public static function retentionDays(): int
    {
        $v = Mailer::env('MAIL_QUEUE_RETENTION_DAYS');
        return $v === '' ? self::DEFAULT_RETENTION_DAYS : max(1, (int) $v);
    }

    /** Abre o lock com permissão 0600. */
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
     * Processa um lote. Devolve null se outro worker tem o lock; senão
     * ['enviados' => n, 'ignorados' => n, 'falhas' => n, 'desistencias' => n, 'purgados' => n].
     */
    public static function run(MailQueue $queue, string $lockFile, int $batch = self::DEFAULT_BATCH, ?int $retentionDays = null, int $maxSeconds = self::MAX_RUN_SECONDS): ?array
    {
        $lock = self::openLock($lockFile);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            $deliver = self::$deliver ?? [Mailer::class, 'deliverAccountMail'];
            $report = ['enviados' => 0, 'ignorados' => 0, 'falhas' => 0, 'desistencias' => 0, 'purgados' => 0];
            $deadline = microtime(true) + min($maxSeconds, self::MAX_RUN_SECONDS);
            for ($i = 0; $i < $batch && microtime(true) < $deadline; $i++) {
                $jobs = $queue->reserve(1);
                if ($jobs === []) {
                    break;
                }
                $job = $jobs[0];
                try {
                    $sent = $deliver($job['type'], $job['recipient']);
                    $queue->ack($job['id']);
                    $report[$sent ? 'enviados' : 'ignorados']++;
                } catch (Throwable $e) {
                    $report['falhas']++;
                    if ($queue->fail($job['id'], $e::class)) {
                        $report['desistencias']++;
                        error_log('mail_queue: e-mail #' . $job['id'] . ' desistido após ' . MailQueues::MAX_ATTEMPTS . ' tentativas (' . $e::class . ')');
                    }
                }
            }
            $report['purgados'] = $queue->purge($retentionDays ?? self::retentionDays());
            flock($lock, LOCK_UN);
            return $report;
        } finally {
            fclose($lock);
        }
    }
}
