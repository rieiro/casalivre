<?php
// Self-test of claim_harness.php (the two-process claim harness for queue workers, e.g. the upcoming calendar worker):
// two real processes race on the same mail/image queue (PostgreSQL; Valkey when phpredis and VALKEY_SOCKET are there)
// and every job must be processed exactly once; a worker without the claim guard must be caught as double processing.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/claim_harness.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/MailQueue.php';
require_once CL_ROOT . '/src/ImageQueue.php';

echo "claim_check\n";
$c = claim_check([['a', 'b'], ['c', 'a'], ['d', 'd']], ['a', 'b', 'c', 'e']);
t_eq(['a' => [0, 1], 'd' => [2, 2]], $c['dupes'], 'duplicates across and within workers');
t_eq(['e'], $c['missing'], 'missing job');
t_eq(['d'], $c['extra'], 'unknown job');

t_temp_db();
$driver = getenv('QUEUE_DRIVER');
putenv('QUEUE_DRIVER=db');
$fill = static function (int $n, string $tag): array {
    $q = MailQueues::make();
    $keys = [];
    for ($i = 0; $i < $n; $i++) {
        $k = "claim-$tag-$i@casalivre.test";
        if ($q->push('reset', $k)) {
            $keys[] = $k;
        }
    }
    t_eq($n, count($keys), "$n $tag jobs queued");
    return $keys;
};

echo "two SQL mail workers, 200 jobs, batch 5\n";
$keys = $fill(200, 'sql');
$race = claim_race([claim_worker_cmd('mail', 5), claim_worker_cmd('mail', 5)], ['env' => ['QUEUE_DRIVER' => 'db'], 'work_us' => 1000]);
claim_assert_once($race['logs'], $keys, 'sql');
t_eq([0, 0], $race['exit'], 'both workers exit 0 (' . trim(implode(' ', $race['stderr'])) . ')');
t_ok(count($race['logs'][0]) > 0 && count($race['logs'][1]) > 0, 'both workers processed jobs (' . count($race['logs'][0]) . ' + ' . count($race['logs'][1]) . ', ' . round($race['seconds'], 2) . ' s)');
t_eq(['sent' => 200], array_map('intval', db()->query('SELECT status, COUNT(*) FROM mail_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR)), 'every row is sent, none left processing');

echo "four SQL workers, batch 1\n";
$keys = $fill(120, 'four');
$race = claim_race(array_fill(0, 4, claim_worker_cmd('mail', 1)), ['env' => ['QUEUE_DRIVER' => 'db'], 'work_us' => 500]);
claim_assert_once($race['logs'], $keys, 'sql x4');
t_eq([0, 0, 0, 0], $race['exit'], 'all four exit 0');

echo "the harness catches a worker without the claim guard\n";
$keys = $fill(20, 'broken');
$race = claim_race([claim_worker_cmd('mail', 20, 'broken'), claim_worker_cmd('mail', 20, 'broken')], ['env' => ['QUEUE_DRIVER' => 'db'], 'work_us' => 20000]);
$c = claim_check($race['logs'], $keys);
t_ok(count($c['dupes']) > 0, 'double processing detected (' . count($c['dupes']) . ' jobs twice)');
db()->exec("UPDATE mail_queue SET status = 'sent' WHERE status = 'pending' AND recipient LIKE 'claim-broken-%'");

echo "two SQL image workers\n";
$owner = t_seed_owner();
$ins = db()->prepare('INSERT INTO photos (property_id, path, status, uuid, original) VALUES (?, ?, ?, ?, ?) RETURNING id');
$photoIds = [];
for ($i = 0; $i < 60; $i++) {
    $ins->execute([(int) $owner['house_id'], '', 'processing', bin2hex(random_bytes(16)), "p$i.jpg"]);
    $photoIds[] = (string) $ins->fetchColumn();
    ImageQueues::make()->push((int) end($photoIds));
}
$race = claim_race([claim_worker_cmd('image', 3), claim_worker_cmd('image', 3)], ['env' => ['QUEUE_DRIVER' => 'db'], 'work_us' => 1000]);
claim_assert_once($race['logs'], $photoIds, 'sql image');
t_eq([0, 0], $race['exit'], 'both image workers exit 0 (' . trim(implode(' ', $race['stderr'])) . ')');

echo "two Valkey mail workers\n";
$socket = (string) getenv('VALKEY_SOCKET');
if (!extension_loaded('redis') || $socket === '') {
    echo "  SKIPPED valkey (phpredis or VALKEY_SOCKET is absent; run via ops/dev-env/test-pg.sh)\n";
} else {
    $ns = 'c' . bin2hex(random_bytes(6));
    putenv('QUEUE_DRIVER=valkey');
    putenv('VALKEY_QUEUE_NS=' . $ns);
    $r = Valkey::connect();
    // No SCAN in the cl_app ACL: delete the namespace's known keys (seq, lists, zsets, job hashes, cooldowns).
    $cleanup = static function (array $recipients) use ($r, $ns): void {
        try {
            $p = 'cl:q:' . $ns . ':mail:';
            $last = (int) $r->get($p . 'seq');
            $del = [$p . 'seq', $p . 'ready', $p . 'processing', $p . 'delayed', $p . 'reserved'];
            for ($id = 1; $id <= $last; $id++) {
                $del[] = $p . 'job:' . $id;
            }
            foreach ($recipients as $k) {
                $del[] = $p . 'cd:' . DbMailQueue::cooldownKey('reset', $k);
            }
            foreach (array_chunk($del, 200) as $chunk) {
                $r->del($chunk);
            }
        } catch (Throwable) {
        }
    };
    $keys = $fill(200, 'vk');
    $race = claim_race([claim_worker_cmd('mail', 5), claim_worker_cmd('mail', 5)], ['env' => ['QUEUE_DRIVER' => 'valkey', 'VALKEY_QUEUE_NS' => $ns], 'work_us' => 1000]);
    claim_assert_once($race['logs'], $keys, 'valkey');
    t_eq([0, 0], $race['exit'], 'both valkey workers exit 0 (' . trim(implode(' ', $race['stderr'])) . ')');
    t_ok(count($race['logs'][0]) > 0 && count($race['logs'][1]) > 0, 'both valkey workers processed jobs (' . count($race['logs'][0]) . ' + ' . count($race['logs'][1]) . ')');
    t_eq(0, (int) $r->lLen('cl:q:' . $ns . ':mail:ready') + (int) $r->lLen('cl:q:' . $ns . ':mail:processing'), 'nothing left in ready or processing');
    $cleanup($keys);
    t_eq(0, (int) $r->exists('cl:q:' . $ns . ':mail:seq', 'cl:q:' . $ns . ':mail:job:1'), 'namespace keys cleaned up');
    putenv('VALKEY_QUEUE_NS');
}
putenv($driver === false ? 'QUEUE_DRIVER' : 'QUEUE_DRIVER=' . $driver);
t_done();
