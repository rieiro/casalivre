<?php

declare(strict_types=1);

require_once __DIR__ . '/JobQueue.php';
require_once __DIR__ . '/ValkeyJobQueue.php';
require_once dirname(__DIR__) . '/conta/Account/Email.php';

/**
 * Fila de e-mails de conta. A requisição só enfileira o tipo e o e-mail (push); o worker
 * (scripts/mail-worker.php) reserva, gera token/link, envia e confirma (ack) ou devolve com erro (fail).
 * Nenhum token, link ou corpo de e-mail passa pela fila.
 */
interface MailQueue extends JobQueue
{
    public const TYPES = ['activation', 'reset', 'exists', 'domain-notice'];
    public const COOLDOWN_SECONDS = 300;
    /** 'domain-notice': aviso aos admins de domínio de grupo que perdeu a verificação (DomainReverification); intervalo padrão. */
    /** Aviso 'exists' (alguém tentou cadastrar o e-mail / reenvio da ativação): 1 por destinatário a cada 24 h. */
    public const EXISTS_COOLDOWN_SECONDS = 86400;

    /** Enfileira (tipo, e-mail). Devolve false se já houve pedido igual dentro do intervalo do tipo (MailQueues::cooldownSeconds). */
    public function push(string $type, string $recipient): bool;
}

final class MailQueues
{
    public const MAX_ATTEMPTS = Queues::MAX_ATTEMPTS;
    public const BACKOFF = Queues::BACKOFF;

    /** Queue from QUEUE_DRIVER (db, the default: the mail_queue table in PostgreSQL; or valkey). */
    public static function make(): MailQueue
    {
        return match (Queues::driver()) {
            'db' => new DbMailQueue(db()),
            'valkey' => new ValkeyMailQueue(Valkey::connect(Valkey::queueTimeout())),
        };
    }

    /** Intervalo mínimo entre pedidos iguais (tipo + destinatário): exists 24 h, demais 5 min. */
    public static function cooldownSeconds(string $type): int
    {
        return $type === 'exists' ? MailQueue::EXISTS_COOLDOWN_SECONDS : MailQueue::COOLDOWN_SECONDS;
    }

    public static function backoffSeconds(int $attempts): int
    {
        return Queues::backoffSeconds($attempts);
    }
}

/** Queue in the mail_queue table of the app database (PostgreSQL). */
final class DbMailQueue extends SqlJobQueue implements MailQueue
{
    protected function table(): string
    {
        return 'mail_queue';
    }

    protected function dataColumns(): array
    {
        return ['type', 'recipient'];
    }

    /** O destinatário é apagado quando o e-mail termina (enviado ou desistido). */
    protected function clearOnDone(): array
    {
        return ['recipient' => ''];
    }

    protected function shape(array $row): array
    {
        return ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'recipient' => (string) $row['recipient'], 'attempts' => (int) $row['attempts']];
    }

    /** Chave do cooldown (HMAC de tipo+e-mail); sobrevive à limpeza do destinatário. */
    public static function cooldownKey(string $type, string $recipient): string
    {
        $salt = (string) (getenv('LOG_SALT') ?: 'casalivre-mail-queue');
        return hash_hmac('sha256', $type . "\n" . Email::normalize($recipient), $salt);
    }

    public function push(string $type, string $recipient): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de e-mail inválido.');
        }
        $key = self::cooldownKey($type, $recipient);
        $recent = $this->pdo->prepare('SELECT 1 FROM mail_queue WHERE cooldown_key = ? AND created_at > ? LIMIT 1');
        $recent->execute([$key, self::now(-MailQueues::cooldownSeconds($type))]);
        $hit = $recent->fetchColumn() !== false;
        $recent->closeCursor();
        if ($hit) {
            return false;
        }
        $this->insertJob(['type' => $type, 'recipient' => Email::normalize($recipient), 'cooldown_key' => $key]);
        return true;
    }
}

/** Mail queue on Valkey (cl:q:mail:*). Same payload (type, e-mail) and cooldown as the SQL queue. */
final class ValkeyMailQueue extends ValkeyJobQueue implements MailQueue
{
    protected function name(): string
    {
        return 'mail';
    }

    protected function dataFields(): array
    {
        return ['type', 'recipient'];
    }

    protected function clearOnDone(): array
    {
        return ['recipient'];
    }

    public function push(string $type, string $recipient): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de e-mail inválido.');
        }
        // Cooldown: atomic SET NX EX on the HMAC key (no address in the key) before the insert, so concurrent
        // pushes cannot both pass. If the insert fails, the key is removed so a retry is accepted.
        $cd = $this->key('cd:' . DbMailQueue::cooldownKey($type, $recipient));
        if ($this->r->set($cd, '1', ['nx', 'ex' => MailQueues::cooldownSeconds($type)]) !== true) {
            return false;
        }
        try {
            $this->insertJob(['type' => $type, 'recipient' => Email::normalize($recipient)]);
        } catch (Throwable $e) {
            $this->r->del($cd);
            throw $e;
        }
        return true;
    }
}
