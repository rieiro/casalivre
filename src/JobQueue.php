<?php

declare(strict_types=1);

/**
 * Base genérica das filas de trabalho (e-mail, imagens). A requisição só enfileira; um worker por cron
 * (QueueWorker) reserva, processa e confirma (ack) ou devolve com erro (fail, com backoff).
 */
interface JobQueue
{
    /**
     * Reserva até $limit itens prontos, marcando-os como processing.
     *
     * @return list<array<string, mixed>> cada item tem id, attempts e as colunas de dados da fila
     */
    public function reserve(int $limit): array;

    /** Concluído (feito ou descartado): status sent. */
    public function ack(int $id): void;

    /** Falha: conta a tentativa e reagenda (backoff); devolve true se desistiu (status failed). */
    public function fail(int $id, string $error = ''): bool;

    /** Apaga sent/failed mais antigos que $days dias. Devolve quantos apagou. */
    public function purge(int $days): int;
}

final class Queues
{
    public const MAX_ATTEMPTS = 5;
    /** Atraso (s) antes da próxima tentativa, pelo número de falhas já ocorridas. */
    public const BACKOFF = [1 => 60, 2 => 300, 3 => 900];

    /**
     * Queue driver (QUEUE_DRIVER: sqlite, the default, or valkey). Single switch point, used by the
     * MailQueues::make()/ImageQueues::make() factories; producers and workers do not change.
     */
    public static function driver(): string
    {
        Db::loadEnv();
        $driver = strtolower(trim((string) (getenv('QUEUE_DRIVER') ?: 'sqlite')));
        if (!in_array($driver, ['sqlite', 'valkey'], true)) {
            throw new RuntimeException('QUEUE_DRIVER não suportado: ' . $driver);
        }
        return $driver;
    }

    public static function backoffSeconds(int $attempts): int
    {
        return self::BACKOFF[min(max(1, $attempts), 3)];
    }
}

/**
 * Fila numa tabela do banco da aplicação (SQL portável sqlite/pgsql). A tabela tem: id, status,
 * attempts, last_error, next_attempt_at, created_at, updated_at e as colunas de dados (dataColumns()).
 */
abstract class SqlJobQueue implements JobQueue
{
    public const STALE_SECONDS = 600;

    public function __construct(protected PDO $pdo)
    {
    }

    abstract protected function table(): string;

    /** @return list<string> colunas de dados devolvidas por reserve() */
    abstract protected function dataColumns(): array;

    /** Colunas zeradas quando o item termina (ack ou desistência), ex.: o destinatário do e-mail. @return array<string, string> */
    protected function clearOnDone(): array
    {
        return [];
    }

    protected static function now(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    protected function insertJob(array $data): void
    {
        $now = self::now();
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $this->table() . ' (' . implode(', ', $cols) . ", status, attempts, last_error, next_attempt_at, created_at, updated_at)
            VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", 'pending', 0, '', ?, ?, ?)";
        $this->pdo->prepare($sql)->execute([...array_values($data), $now, $now, $now]);
    }

    public function reserve(int $limit): array
    {
        $t = $this->table();
        $now = self::now();
        // Reserva órfã (worker morreu no meio): volta para pending depois de STALE_SECONDS.
        $this->pdo->prepare("UPDATE $t SET status = 'pending', updated_at = ? WHERE status = 'processing' AND updated_at < ?")
            ->execute([$now, self::now(-static::STALE_SECONDS)]);
        $st = $this->pdo->prepare('SELECT id, attempts, ' . implode(', ', $this->dataColumns()) . " FROM $t
            WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT " . max(1, $limit));
        $st->execute([$now]);
        $claim = $this->pdo->prepare("UPDATE $t SET status = 'processing', updated_at = ? WHERE id = ? AND status = 'pending'");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claim->execute([$now, (int) $row['id']]);
            if ($claim->rowCount() === 1) {
                $out[] = $this->shape($row);
            }
        }
        return $out;
    }

    /** Converte a linha reservada no formato do item. */
    protected function shape(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['attempts'] = (int) $row['attempts'];
        return $row;
    }

    private function clearSql(): string
    {
        $sql = '';
        foreach ($this->clearOnDone() as $col => $value) {
            $sql .= ', ' . $col . ' = ' . $this->pdo->quote($value);
        }
        return $sql;
    }

    public function ack(int $id): void
    {
        $this->pdo->prepare('UPDATE ' . $this->table() . " SET status = 'sent'" . $this->clearSql() . ', updated_at = ? WHERE id = ?')
            ->execute([self::now(), $id]);
    }

    public function fail(int $id, string $error = ''): bool
    {
        $t = $this->table();
        $st = $this->pdo->prepare("SELECT attempts FROM $t WHERE id = ?");
        $st->execute([$id]);
        $attempts = (int) $st->fetchColumn() + 1;
        $st->closeCursor();
        $giveUp = $attempts >= Queues::MAX_ATTEMPTS;
        $error = substr($error, 0, 200);
        if ($giveUp) {
            $this->pdo->prepare("UPDATE $t SET status = 'failed'" . $this->clearSql() . ', attempts = ?, last_error = ?, updated_at = ? WHERE id = ?')
                ->execute([$attempts, $error, self::now(), $id]);
            return true;
        }
        $this->pdo->prepare("UPDATE $t SET status = 'pending', attempts = ?, last_error = ?, next_attempt_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$attempts, $error, self::now(Queues::backoffSeconds($attempts)), self::now(), $id]);
        return false;
    }

    public function purge(int $days): int
    {
        $st = $this->pdo->prepare('DELETE FROM ' . $this->table() . " WHERE status IN ('sent', 'failed') AND updated_at < ?");
        $st->execute([self::now(-max(0, $days) * 86400)]);
        return $st->rowCount();
    }
}
