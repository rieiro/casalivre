<?php

declare(strict_types=1);

/**
 * Fila de e-mails. A requisição só enfileira (push); o worker (scripts/mail-worker.php) reserva, envia e
 * confirma (ack) ou devolve com erro (fail). Implementação escolhida por QUEUE_DRIVER (MailQueues::make()).
 */
interface MailQueue
{
    /** Enfileira um e-mail; devolve o id. */
    public function push(string $recipient, string $subject, string $body): int;

    /**
     * Reserva até $limit e-mails prontos (pending com next_attempt_at <= agora), marcando-os como
     * processing para que outro worker não os pegue.
     *
     * @return list<array{id: int, recipient: string, subject: string, body: string, attempts: int}>
     */
    public function reserve(int $limit): array;

    /** Envio concluído: status sent. */
    public function ack(int $id): void;

    /**
     * Falha no envio: conta a tentativa e reagenda com backoff (1, 5, 15 min); após MAX_ATTEMPTS fica failed.
     * Devolve true se desistiu (status failed).
     */
    public function fail(int $id): bool;

    /** Apaga sent/failed mais antigos que $days dias (guardam tokens). Devolve quantos apagou. */
    public function purge(int $days): int;
}

final class MailQueues
{
    public const MAX_ATTEMPTS = 5;
    /** Atraso (s) antes da próxima tentativa, pelo número de falhas já ocorridas; depois da 3ª, fica 15 min. */
    public const BACKOFF = [1 => 60, 2 => 300, 3 => 900];

    /** Fila configurada em QUEUE_DRIVER (padrão sqlite). Ponto de troca para uma futura RedisMailQueue. */
    public static function make(): MailQueue
    {
        Db::loadEnv();
        $driver = strtolower(trim((string) (getenv('QUEUE_DRIVER') ?: 'sqlite')));
        return match ($driver) {
            'sqlite', 'db' => new SqliteMailQueue(db()),
            // 'redis' => new RedisMailQueue(...),  // futuro; ainda não implementado
            default => throw new RuntimeException('QUEUE_DRIVER não suportado: ' . $driver),
        };
    }

    public static function backoffSeconds(int $attempts): int
    {
        return self::BACKOFF[min($attempts, 3)] ?? 900;
    }
}

/** Fila na tabela mail_queue do banco da aplicação (SQLite, mas SQL portável para pgsql). */
final class SqliteMailQueue implements MailQueue
{
    public const STALE_SECONDS = 600;

    public function __construct(private PDO $pdo)
    {
    }

    private static function now(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    public function push(string $recipient, string $subject, string $body): int
    {
        $now = self::now();
        $st = $this->pdo->prepare("INSERT INTO mail_queue (recipient, subject, body, status, attempts, next_attempt_at, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', 0, ?, ?, ?)");
        $st->execute([$recipient, $subject, $body, $now, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function reserve(int $limit): array
    {
        $now = self::now();
        // Reserva órfã (worker morreu no meio): volta para pending depois de STALE_SECONDS.
        $this->pdo->prepare("UPDATE mail_queue SET status = 'pending', updated_at = ? WHERE status = 'processing' AND updated_at < ?")
            ->execute([$now, self::now(-self::STALE_SECONDS)]);
        $st = $this->pdo->prepare("SELECT id, recipient, subject, body, attempts FROM mail_queue
            WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT " . max(1, $limit));
        $st->execute([$now]);
        $claim = $this->pdo->prepare("UPDATE mail_queue SET status = 'processing', updated_at = ? WHERE id = ? AND status = 'pending'");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claim->execute([$now, (int) $row['id']]);
            if ($claim->rowCount() === 1) {
                $out[] = ['id' => (int) $row['id'], 'recipient' => (string) $row['recipient'], 'subject' => (string) $row['subject'],
                    'body' => (string) $row['body'], 'attempts' => (int) $row['attempts']];
            }
        }
        return $out;
    }

    public function ack(int $id): void
    {
        $this->pdo->prepare("UPDATE mail_queue SET status = 'sent', updated_at = ? WHERE id = ?")->execute([self::now(), $id]);
    }

    public function fail(int $id): bool
    {
        $st = $this->pdo->prepare('SELECT attempts FROM mail_queue WHERE id = ?');
        $st->execute([$id]);
        $attempts = (int) $st->fetchColumn() + 1;
        $giveUp = $attempts >= MailQueues::MAX_ATTEMPTS;
        $this->pdo->prepare('UPDATE mail_queue SET status = ?, attempts = ?, next_attempt_at = ?, updated_at = ? WHERE id = ?')->execute([
            $giveUp ? 'failed' : 'pending', $attempts, self::now($giveUp ? 0 : MailQueues::backoffSeconds($attempts)), self::now(), $id,
        ]);
        return $giveUp;
    }

    public function purge(int $days): int
    {
        $st = $this->pdo->prepare("DELETE FROM mail_queue WHERE status IN ('sent', 'failed') AND updated_at < ?");
        $st->execute([self::now(-max(0, $days) * 86400)]);
        return $st->rowCount();
    }
}
