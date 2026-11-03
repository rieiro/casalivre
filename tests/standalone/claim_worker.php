<?php
// Generic worker for claim_harness.php: php claim_worker.php <CL_ROOT> <mail|image> [batch] [claim|broken]
// claim:  MailQueues/ImageQueues::make() (QUEUE_DRIVER db or valkey), reserve(batch), log each job key, ack.
// broken: reads pending rows of the SQL table without the claim guard, so two workers take the same job (proves
//         that the harness detects double processing). Exits 0 after ~300 ms with nothing left, 3 on a 25 s deadline.
// Job key: the mail recipient or the image photo_id (stable across drivers).
declare(strict_types=1);
[, $root, $queue] = $argv;
$batch = max(1, (int) ($argv[3] ?? 1));
$mode = $argv[4] ?? 'claim';
require $root . '/src/Db.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/ValkeyJobQueue.php';
require_once $root . '/src/' . ($queue === 'image' ? 'ImageQueue.php' : 'MailQueue.php');
$log = (string) getenv('CL_CLAIM_LOG');
$go = (string) getenv('CL_CLAIM_GO');
$workUs = (int) (getenv('CL_CLAIM_WORK_US') ?: 0);
$deadline = microtime(true) + 25;
while ($go !== '' && !is_file($go) && microtime(true) < $deadline) {
    usleep(2000);
}
$q = $queue === 'image' ? ImageQueues::make() : MailQueues::make();
$key = static fn (array $job): string => $queue === 'image' ? (string) $job['photo_id'] : (string) $job['recipient'];
$record = static function (string $k) use ($log): void {
    $fh = fopen($log, 'a');
    flock($fh, LOCK_EX);
    fwrite($fh, $k . "\n");
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
};
$idleSince = null;
while (microtime(true) < $deadline) {
    if ($mode === 'broken') {
        $table = $queue === 'image' ? 'image_queue' : 'mail_queue';
        $col = $queue === 'image' ? 'photo_id' : 'recipient';
        $rows = db()->query("SELECT id, $col AS k FROM $table WHERE status = 'pending' ORDER BY id LIMIT $batch")->fetchAll(PDO::FETCH_ASSOC);
        $jobs = [];
        foreach ($rows as $row) {
            usleep($workUs);
            db()->prepare("UPDATE $table SET status = 'sent' WHERE id = ?")->execute([(int) $row['id']]);
            $jobs[] = ['k' => (string) $row['k']];
        }
        foreach ($jobs as $job) {
            $record($job['k']);
        }
    } else {
        $jobs = $q->reserve($batch);
        foreach ($jobs as $job) {
            $record($key($job));
            if ($workUs > 0) {
                usleep($workUs);
            }
            $q->ack((int) $job['id']);
        }
    }
    if ($jobs === []) {
        $idleSince ??= microtime(true);
        if (microtime(true) - $idleSince > 0.3) {
            exit(0);
        }
        usleep(5000);
    } else {
        $idleSince = null;
    }
}
exit(3);
