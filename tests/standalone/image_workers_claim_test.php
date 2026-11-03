<?php

declare(strict_types=1);

// Two image workers must not process the same photo. The claim is one UPDATE ... WHERE status = 'pending'.
// SQL is required. Valkey runs when phpredis and VALKEY_SOCKET are present (CI) and is skipped otherwise.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/ImageQueue.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';

t_temp_db();
$driver = getenv('QUEUE_DRIVER');
putenv('QUEUE_DRIVER=db');
$owner = t_seed_owner();
$ins = db()->prepare('INSERT INTO photos (property_id, path, status, uuid, original) VALUES (?, ?, ?, ?, ?) RETURNING id');
$ins->execute([(int) $owner['house_id'], '', 'processing', bin2hex(random_bytes(16)), 'a.jpg']);
$photoId = (int) $ins->fetchColumn();
t_ok(ImageQueues::make()->push($photoId), 'job queued');
$jobId = (int) db()->query('SELECT id FROM image_queue WHERE photo_id = ' . $photoId)->fetchColumn();

echo "two sql workers\n";
$dir = sys_get_temp_dir() . '/cl-img-' . bin2hex(random_bytes(3));
mkdir($dir);
$held = $dir . '/held';
$go = $dir . '/go';
$err = $dir . '/err';
file_put_contents($dir . '/hold.php', <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1] . '/src/Db.php';
$pdo = db();
$pdo->beginTransaction();
$st = $pdo->prepare("UPDATE image_queue SET status = 'processing', updated_at = ? WHERE id = ? AND status = 'pending'");
$st->execute([gmdate('Y-m-d H:i:s'), (int) $argv[2]]);
if ($st->rowCount() !== 1) {
    fwrite(STDERR, "claim failed\n");
    exit(1);
}
file_put_contents($argv[3], '1');
$deadline = time() + 10;
while (!is_file($argv[4]) && time() < $deadline) {
    usleep(20000);
}
$pdo->commit();
PHP);
$proc = proc_open(
    [...t_php_cmd(), $dir . '/hold.php', CL_ROOT, (string) $jobId, $held, $go],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', $err, 'w']],
    $pipes,
    CL_ROOT
);
$deadline = microtime(true) + 10;
while (!is_file($held) && microtime(true) < $deadline) {
    usleep(20000);
}
t_ok(is_file($held), 'first worker holds the claim (' . (string) @file_get_contents($err) . ')');
db()->exec("SET lock_timeout = '2s'");
$blocked = false;
try {
    ImageQueues::make()->reserve(1);
} catch (PDOException $e) {
    $blocked = str_contains($e->getMessage(), 'lock timeout');
} finally {
    db()->exec('SET lock_timeout = 0');
    file_put_contents($go, '1');
}
t_ok($blocked, 'second worker does not take the locked job');
t_eq(0, proc_close($proc), 'first worker commits (' . (string) file_get_contents($err) . ')');
t_eq([], ImageQueues::make()->reserve(1), 'the job is not pending after the first claim');
t_eq('processing', (string) db()->query('SELECT status FROM image_queue WHERE id = ' . $jobId)->fetchColumn(), 'the photo job stays with the first worker');

echo "valkey\n";
$socket = (string) getenv('VALKEY_SOCKET');
if (!extension_loaded('redis') || $socket === '') {
    echo "  SKIPPED valkey (phpredis or VALKEY_SOCKET is absent; CI has Valkey and must run this)\n";
} else {
    putenv('QUEUE_DRIVER=valkey');
    putenv('VALKEY_QUEUE_NS=i' . bin2hex(random_bytes(4)));
    $first = ImageQueues::make();
    t_ok($first->push($photoId), 'valkey job queued');
    $claimed = $first->reserve(1);
    $second = ImageQueues::make()->reserve(1);
    t_eq([$photoId], array_map(static fn (array $job): int => (int) $job['photo_id'], $claimed), 'first valkey worker claims the photo');
    t_eq([], $second, 'second valkey worker does not claim it');
}
putenv($driver === false ? 'QUEUE_DRIVER' : 'QUEUE_DRIVER=' . $driver);

echo "missing WebP\n";
$src = (string) file_get_contents(CL_ROOT . '/scripts/queue-worker.php');
t_ok(str_contains($src, "halt('GD WebP missing')"), 'image worker alerts when WebP is missing');
t_ok(!str_contains($src, 'image queue skipped'), 'image queue is not skipped');
if (!QueueWorkerLoop::hasWebp()) {
    $stderr = $dir . '/webp';
    $webp = proc_open(
        [PHP_BINARY, CL_ROOT . '/scripts/queue-worker.php', 'image', '--once'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']],
        $pipes,
        CL_ROOT
    );
    $alert = '';
    for ($i = 0; $i < 100 && !str_contains($alert, 'ALERT'); $i++) {
        usleep(20000);
        $alert = (string) @file_get_contents($stderr);
    }
    proc_terminate($webp, 15);
    $status = proc_get_status($webp);
    for ($i = 0; $i < 100 && $status['running']; $i++) {
        usleep(20000);
        $status = proc_get_status($webp);
    }
    if ($status['running']) {
        proc_terminate($webp, 9);
    }
    proc_close($webp);
    t_ok(str_contains($alert, 'queue-worker: ALERT GD WebP missing'), 'missing WebP writes an ALERT line');
    t_eq(1, $status['exitcode'], 'missing WebP exits non-zero');
}

t_done();
