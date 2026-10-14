<?php

declare(strict_types=1);

require_once __DIR__ . '/MailQueue.php';
require_once __DIR__ . '/Mailer.php';

/**
 * Um lote do worker de e-mail: lock exclusivo (flock), reserva, entrega via Mailer::deliver(), ack/fail com
 * backoff (1, 5, 15 min; desiste após 5 tentativas) e purga de sent/failed antigos (guardam tokens).
 * Logs nunca levam endereço nem link.
 */
final class MailWorker
{
    public const DEFAULT_BATCH = 50;
    public const DEFAULT_RETENTION_DAYS = 7;

    /** Entrega (troca em testes); padrão Mailer::deliver. @var null|callable(string, string, string): void */
    public static $deliver = null;

    public static function retentionDays(): int
    {
        Db::loadEnv();
        $v = getenv('MAIL_QUEUE_RETENTION_DAYS');
        return ($v === false || trim((string) $v) === '') ? self::DEFAULT_RETENTION_DAYS : max(1, (int) $v);
    }

    /**
     * Processa um lote. Devolve null se outro worker tem o lock; senão
     * ['enviados' => n, 'falhas' => n, 'desistencias' => n, 'purgados' => n].
     */
    public static function run(MailQueue $queue, string $lockFile, int $batch = self::DEFAULT_BATCH, ?int $retentionDays = null): ?array
    {
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            throw new RuntimeException('Não foi possível abrir o lock do worker.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            $deliver = self::$deliver ?? [Mailer::class, 'deliver'];
            $report = ['enviados' => 0, 'falhas' => 0, 'desistencias' => 0, 'purgados' => 0];
            foreach ($queue->reserve($batch) as $job) {
                try {
                    $deliver($job['recipient'], $job['subject'], $job['body']);
                    $queue->ack($job['id']);
                    $report['enviados']++;
                } catch (Throwable $e) {
                    $report['falhas']++;
                    if ($queue->fail($job['id'])) {
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
