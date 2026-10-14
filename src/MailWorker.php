<?php

declare(strict_types=1);

require_once __DIR__ . '/MailQueue.php';
require_once __DIR__ . '/QueueWorker.php';
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
    public const MAX_RUN_SECONDS = QueueWorker::MAX_RUN_SECONDS;

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
        return QueueWorker::openLock($lockFile);
    }

    /**
     * Processa um lote. Devolve null se outro worker tem o lock; senão
     * ['enviados' => n, 'ignorados' => n, 'falhas' => n, 'desistencias' => n, 'purgados' => n].
     */
    public static function run(MailQueue $queue, string $lockFile, int $batch = self::DEFAULT_BATCH, ?int $retentionDays = null, int $maxSeconds = self::MAX_RUN_SECONDS): ?array
    {
        $deliver = self::$deliver ?? [Mailer::class, 'deliverAccountMail'];
        return QueueWorker::run(
            $queue,
            $lockFile,
            static fn (array $job): bool => (bool) $deliver($job['type'], $job['recipient']),
            $batch,
            $retentionDays ?? self::retentionDays(),
            $maxSeconds,
            null,
            'mail_queue: e-mail'
        );
    }
}
