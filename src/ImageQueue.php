<?php

declare(strict_types=1);

require_once __DIR__ . '/JobQueue.php';
require_once __DIR__ . '/ValkeyJobQueue.php';

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
    /** Queue from QUEUE_DRIVER (db by default: the image_queue table in PostgreSQL; or valkey). */
    public static function make(): ImageQueue
    {
        return match (Queues::driver()) {
            'db' => new DbImageQueue(db()),
            'valkey' => new ValkeyImageQueue(Valkey::connect(Valkey::queueTimeout())),
        };
    }
}

/** Fila na tabela image_queue do banco da aplicação. */
final class DbImageQueue extends SqlJobQueue implements ImageQueue
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

/** Image queue on Valkey (cl:q:image:*). */
final class ValkeyImageQueue extends ValkeyJobQueue implements ImageQueue
{
    protected function name(): string
    {
        return 'image';
    }

    protected function dataFields(): array
    {
        return ['photo_id'];
    }

    protected function shape(array $job): array
    {
        return ['id' => $job['id'], 'photo_id' => (int) $job['photo_id'], 'attempts' => $job['attempts']];
    }

    public function push(int $photoId): bool
    {
        $this->insertJob(['photo_id' => $photoId]);
        return true;
    }
}
