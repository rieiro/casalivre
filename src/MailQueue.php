<?php

declare(strict_types=1);

/**
 * Fila de e-mails de conta. A requisição só enfileira o tipo e o e-mail (push); o worker
 * (scripts/mail-worker.php) reserva, gera token/link, envia e confirma (ack) ou devolve com erro (fail).
 * Nenhum token, link ou corpo de e-mail passa pela fila.
 */
interface MailQueue
{
    public const TYPES = ['activation', 'reset'];
    public const COOLDOWN_SECONDS = 300;

    /** Enfileira (tipo, e-mail). Devolve false se já houve pedido igual nos últimos COOLDOWN_SECONDS. */
    public function push(string $type, string $recipient): bool;

    /**
     * Reserva até $limit itens prontos, marcando-os como processing.
     *
     * @return list<array{id: int, type: string, recipient: string, attempts: int}>
     */
    public function reserve(int $limit): array;

    /** Concluído (enviado ou descartado): status sent; o destinatário é apagado. */
    public function ack(int $id): void;

    /** Falha: conta a tentativa e reagenda; devolve true se desistiu (status failed, destinatário apagado). */
    public function fail(int $id, string $error = ''): bool;

    /** Apaga sent/failed mais antigos que $days dias. Devolve quantos apagou. */
    public function purge(int $days): int;
}

final class MailQueues
{
    public const MAX_ATTEMPTS = 5;
    /** Atraso (s) antes da próxima tentativa, pelo número de falhas já ocorridas. */
    public const BACKOFF = [1 => 60, 2 => 300, 3 => 900];

    /** Fila configurada em QUEUE_DRIVER (padrão sqlite). */
    public static function make(): MailQueue
    {
        Db::loadEnv();
        $driver = strtolower(trim((string) (getenv('QUEUE_DRIVER') ?: 'sqlite')));
        return match ($driver) {
            'sqlite' => new SqliteMailQueue(db()),
            default => throw new RuntimeException('QUEUE_DRIVER não suportado: ' . $driver),
        };
    }

    public static function backoffSeconds(int $attempts): int
    {
        return self::BACKOFF[min(max(1, $attempts), 3)];
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

    /** Chave do cooldown (HMAC de tipo+e-mail); sobrevive à limpeza do destinatário. */
    public static function cooldownKey(string $type, string $recipient): string
    {
        $salt = (string) (getenv('LOG_SALT') ?: 'casalivre-mail-queue');
        return hash_hmac('sha256', $type . "\n" . strtolower(trim($recipient)), $salt);
    }

    public function push(string $type, string $recipient): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de e-mail inválido.');
        }
        $key = self::cooldownKey($type, $recipient);
        $recent = $this->pdo->prepare('SELECT 1 FROM mail_queue WHERE cooldown_key = ? AND created_at > ? LIMIT 1');
        $recent->execute([$key, self::now(-self::COOLDOWN_SECONDS)]);
        if ($recent->fetchColumn() !== false) {
            return false;
        }
        $now = self::now();
        $this->pdo->prepare("INSERT INTO mail_queue (type, recipient, cooldown_key, status, attempts, last_error, next_attempt_at, created_at, updated_at)
            VALUES (?, ?, ?, 'pending', 0, '', ?, ?, ?)")->execute([$type, strtolower(trim($recipient)), $key, $now, $now, $now]);
        return true;
    }

    public function reserve(int $limit): array
    {
        $now = self::now();
        // Reserva órfã (worker morreu no meio): volta para pending depois de STALE_SECONDS.
        $this->pdo->prepare("UPDATE mail_queue SET status = 'pending', updated_at = ? WHERE status = 'processing' AND updated_at < ?")
            ->execute([$now, self::now(-self::STALE_SECONDS)]);
        $st = $this->pdo->prepare("SELECT id, type, recipient, attempts FROM mail_queue
            WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT " . max(1, $limit));
        $st->execute([$now]);
        $claim = $this->pdo->prepare("UPDATE mail_queue SET status = 'processing', updated_at = ? WHERE id = ? AND status = 'pending'");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claim->execute([$now, (int) $row['id']]);
            if ($claim->rowCount() === 1) {
                $out[] = ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'recipient' => (string) $row['recipient'], 'attempts' => (int) $row['attempts']];
            }
        }
        return $out;
    }

    public function ack(int $id): void
    {
        $this->pdo->prepare("UPDATE mail_queue SET status = 'sent', recipient = '', updated_at = ? WHERE id = ?")->execute([self::now(), $id]);
    }

    public function fail(int $id, string $error = ''): bool
    {
        $st = $this->pdo->prepare('SELECT attempts FROM mail_queue WHERE id = ?');
        $st->execute([$id]);
        $attempts = (int) $st->fetchColumn() + 1;
        $giveUp = $attempts >= MailQueues::MAX_ATTEMPTS;
        $error = substr($error, 0, 200);
        if ($giveUp) {
            $this->pdo->prepare("UPDATE mail_queue SET status = 'failed', recipient = '', attempts = ?, last_error = ?, updated_at = ? WHERE id = ?")
                ->execute([$attempts, $error, self::now(), $id]);
            return true;
        }
        $this->pdo->prepare("UPDATE mail_queue SET status = 'pending', attempts = ?, last_error = ?, next_attempt_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$attempts, $error, self::now(MailQueues::backoffSeconds($attempts)), self::now(), $id]);
        return false;
    }

    public function purge(int $days): int
    {
        $st = $this->pdo->prepare("DELETE FROM mail_queue WHERE status IN ('sent', 'failed') AND updated_at < ?");
        $st->execute([self::now(-max(0, $days) * 86400)]);
        return $st->rowCount();
    }
}
