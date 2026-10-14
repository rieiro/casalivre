<?php

declare(strict_types=1);

require_once __DIR__ . '/JobQueue.php';

/**
 * Fila de imagens: o upload só grava o original fora do public/ e enfileira o id da foto; o
 * scripts/image-worker.php gera os WebP. Mesma base (JobQueue/SqlJobQueue/QueueWorker) da MailQueue.
 */
interface ImageQueue extends JobQueue
{
    /** Enfileira a foto para processamento. */
    public function push(int $photoId): bool;
}

final class ImageQueues
{
    /** Fila configurada em QUEUE_DRIVER (padrão sqlite); um driver Redis entra só aqui. */
    public static function make(): ImageQueue
    {
        return match (Queues::driver()) {
            'sqlite' => new SqliteImageQueue(db()),
        };
    }
}

/** Fila na tabela image_queue do banco da aplicação. */
final class SqliteImageQueue extends SqlJobQueue implements ImageQueue
{
    protected function table(): string
    {
        return 'image_queue';
    }

    protected function dataColumns(): array
    {
        return ['photo_id'];
    }

    protected function shape(array $row): array
    {
        return ['id' => (int) $row['id'], 'photo_id' => (int) $row['photo_id'], 'attempts' => (int) $row['attempts']];
    }

    public function push(int $photoId): bool
    {
        $this->insertJob(['photo_id' => $photoId]);
        return true;
    }
}
