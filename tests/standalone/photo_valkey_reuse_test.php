<?php

declare(strict_types=1);

// Photo upload used to call ImageQueues::make() per photo, and that opens one Valkey connection
// (Valkey::connect(Valkey::queueTimeout()), producer timeout 0.5 s). A frozen Valkey then costs
// ~0.5 s times the number of photos. One upload batch keeps a single queue and, after the first
// failure, marks the rest failed without connecting again. The next upload may connect.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/src/PhotoStorage.php';

$tmp = sys_get_temp_dir() . '/cl-photo-vk-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
PhotoStorage::$originals = $tmp . '/orig';
PhotoStorage::$acceptFile = static fn (string $f): bool => is_file($f);
PhotoStorage::$moveFile = static fn (string $from, string $to): bool => copy($from, $to);
$hid = (int) $owner['house_id'];
$uid = (int) $owner['user_id'];

$img = imagecreatetruecolor(8, 8);
ob_start();
imagejpeg($img);
$jpeg = (string) ob_get_clean();
imagedestroy($img);

/** @return array{name: list<string>, tmp_name: list<string>, error: list<int>, size: list<int>} */
$upload = static function (int $n) use ($tmp, $jpeg): array {
    $up = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    for ($i = 0; $i < $n; $i++) {
        $f = $tmp . '/up-' . bin2hex(random_bytes(3)) . '.jpg';
        file_put_contents($f, $jpeg);
        $up['name'][] = 'p.jpg';
        $up['tmp_name'][] = $f;
        $up['error'][] = UPLOAD_ERR_OK;
        $up['size'][] = strlen($jpeg);
    }
    return $up;
};

/** @param list<array{id: int, estado: string, capa: int}> $created */
$statuses = static function (array $created): array {
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $created);
    $st = db()->query('SELECT status FROM photos WHERE id IN (' . implode(',', $ids) . ') ORDER BY id');
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

/** Queue double: each factory call stands for one Valkey::connect inside ImageQueues::make(). */
final class CountingImageQueue implements ImageQueue
{
    public int $pushes = 0;

    public function __construct(private ?Throwable $fail = null)
    {
    }

    public function push(int $photoId): bool
    {
        $this->pushes++;
        if ($this->fail !== null) {
            throw $this->fail;
        }
        return true;
    }

    public function reserve(int $limit): array
    {
        return [];
    }

    public function ack(int $id): void
    {
    }

    public function fail(int $id, string $error = ''): bool
    {
        return false;
    }

    public function giveUp(int $id, string $error = 'stale'): void
    {
    }

    public function purge(int $days): int
    {
        return 0;
    }
}

echo "one connection for the batch\n";
$connects = 0;
$queue = new CountingImageQueue();
PhotoStorage::$queueFactory = static function () use (&$connects, $queue): ImageQueue {
    $connects++;
    return $queue;
};
$saved = PhotoStorage::storeUploads($uid, $hid, $upload(3), $created);
t_eq(3, $saved['saved'], '3 photos stored');
t_eq(1, $connects, 'one connection for 3 photos');
t_eq(3, $queue->pushes, 'all 3 pushed on that same queue');
t_eq(['processing', 'processing', 'processing'], $statuses($created), 'queued photos stay processing');
t_eq(['processing', 'processing', 'processing'], array_column($created, 'estado'), 'created rows report processing');
t_eq(0, (int) db()->query('SELECT COUNT(*) FROM image_queue')->fetchColumn(), 'the batch did not open the real queue factory');

echo "connect failure skips the rest of the batch\n";
$connects = 0;
PhotoStorage::$queueFactory = static function () use (&$connects): ImageQueue {
    $connects++;
    throw new RuntimeException('valkey down');
};
$saved = PhotoStorage::storeUploads($uid, $hid, $upload(3), $created);
t_eq(3, $saved['saved'], '3 photos stored while Valkey is down');
t_eq(1, $connects, 'one failed connect, then no further connects in this upload');
t_eq(['failed', 'failed', 'failed'], $statuses($created), 'remaining photos marked failed without a job');
t_eq(['failed', 'failed', 'failed'], array_column($created, 'estado'), 'created rows report failed');

echo "push failure does not open another connection\n";
$connects = 0;
$queue = new CountingImageQueue(new RuntimeException('enqueue failed'));
PhotoStorage::$queueFactory = static function () use (&$connects, $queue): ImageQueue {
    $connects++;
    return $queue;
};
$saved = PhotoStorage::storeUploads($uid, $hid, $upload(3), $created);
t_eq(3, $saved['saved'], '3 photos stored when the first push fails');
t_eq(1, $connects, 'push failure does not connect again');
t_eq(1, $queue->pushes, 'no further Valkey work after the first push failure');
t_eq(['failed', 'failed', 'failed'], $statuses($created), 'the batch is failed after the first push error');

echo "a later upload connects again\n";
$connects = 0;
$queue = new CountingImageQueue();
PhotoStorage::$queueFactory = static function () use (&$connects, $queue): ImageQueue {
    $connects++;
    return $queue;
};
$saved = PhotoStorage::storeUploads($uid, $hid, $upload(3), $created);
t_eq(3, $saved['saved'], 'a later upload stores 3 photos');
t_eq(1, $connects, 'the next upload opens its own connection');
t_eq(3, $queue->pushes, 'the new connection is reused for that upload');
t_eq(['processing', 'processing', 'processing'], $statuses($created), 'the later upload is queued');

PhotoStorage::$queueFactory = null;
t_done();
