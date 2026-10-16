<?php
// P0-4: Valkey queue driver (QUEUE_DRIVER=valkey) as cl_app: enqueue/reserve/ack (FIFO), fail + backoff,
// give-up clears the recipient, mail cooldown, keys only under cl:, and the cl_app ACL denying anything
// outside cl:* or outside the allowlist. Needs phpredis and VALKEY_SOCKET/VALKEY_USER/VALKEY_PASSWORD_FILE
// (the dev env: ops/dev-env/test-pg.sh); skipped otherwise. Uses only allowlisted commands itself.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/MailQueue.php';
require_once CL_ROOT . '/src/ImageQueue.php';

if (!extension_loaded('redis') || (string) getenv('VALKEY_SOCKET') === '') {
    echo "  SKIPPED (needs phpredis and VALKEY_SOCKET: run via ops/dev-env/test-pg.sh)\n";
    t_done();
}

$r = Valkey::connect();
$tag = 't' . getmypid() . bin2hex(random_bytes(3));
/** Test queue with its own name, so runs never touch the real mail/image queues. */
$q = new class ($r, $tag) extends ValkeyJobQueue {
    public function __construct(Redis $r, private string $tag)
    {
        parent::__construct($r);
    }

    protected function name(): string
    {
        return 'test-' . $this->tag;
    }

    protected function dataFields(): array
    {
        return ['payload', 'recipient'];
    }

    protected function clearOnDone(): array
    {
        return ['recipient'];
    }

    public function push(string $payload): int
    {
        return $this->insertJob(['payload' => $payload, 'recipient' => 'a@b.test']);
    }
};
$cleanup = static function () use ($r, $q): void {
    $keys = [$q->key('seq'), $q->key('ready'), $q->key('processing'), $q->key('reserved'), $q->key('delayed')];
    for ($i = 1; $i <= 10; $i++) {
        $keys[] = $q->key('job:' . $i);
    }
    $r->del($keys);
};
register_shutdown_function($cleanup);

echo "factory\n";
putenv('QUEUE_DRIVER=valkey');
t_ok(MailQueues::make() instanceof ValkeyMailQueue, 'QUEUE_DRIVER=valkey -> ValkeyMailQueue');
t_ok(ImageQueues::make() instanceof ValkeyImageQueue, 'QUEUE_DRIVER=valkey -> ValkeyImageQueue');
putenv('QUEUE_DRIVER');

echo "key prefix\n";
foreach (['seq', 'ready', 'processing', 'reserved', 'delayed', 'job:1'] as $s) {
    t_ok(str_starts_with($q->key($s), 'cl:q:test-' . $tag . ':'), "key($s) under cl:q:");
}

echo "enqueue / reserve / ack\n";
$a = $q->push('one');
$b = $q->push('two');
t_eq(2, (int) $r->lLen($q->key('ready')), 'two ids in ready');
$got = $q->reserve(1);
t_eq(1, count($got), 'reserve(1) returns one');
t_eq($a, $got[0]['id'] ?? null, 'FIFO: first pushed comes first');
t_eq('one', $got[0]['payload'] ?? null, 'payload kept');
t_eq(0, $got[0]['attempts'] ?? null, 'attempts 0');
t_eq(1, (int) $r->lLen($q->key('processing')), 'reserved id in processing');
$q->ack($a);
t_eq(0, (int) $r->lLen($q->key('processing')), 'ack removes from processing');
t_eq(0, (int) $r->exists($q->key('job:' . $a)), 'ack deletes the job hash');

echo "fail + backoff + give up\n";
$got = $q->reserve(5);
t_eq([$b], array_column($got, 'id'), 'second job reserved');
t_eq(false, $q->fail($b, 'RuntimeException'), 'first fail: retry');
t_eq([], $q->reserve(1), 'delayed job is not ready yet');
t_ok((float) ($r->zRange($q->key('delayed'), 0, -1, ['withscores' => true])[(string) $b] ?? 0) >= time() + 59, 'backoff >= 60 s');
$r->zAdd($q->key('delayed'), time() - 1, (string) $b);
$got = $q->reserve(1);
t_eq(1, $got[0]['attempts'] ?? null, 'due job back with attempts 1');
for ($i = 2; $i < Queues::MAX_ATTEMPTS; $i++) {
    $q->fail($b, 'x');
    $r->zAdd($q->key('delayed'), time() - 1, (string) $b);
    $q->reserve(1);
}
t_eq(true, $q->fail($b, 'x'), 'gives up at MAX_ATTEMPTS');
t_eq('failed', $r->hGet($q->key('job:' . $b), 'status'), 'status failed');
t_eq(false, array_key_exists('recipient', $r->hGetAll($q->key('job:' . $b))), 'recipient cleared on give up');
t_ok((int) $r->ttl($q->key('job:' . $b)) > 0, 'given-up job expires');

echo "stale reservation goes back to ready\n";
$c = $q->push('three');
$q->reserve(1);
$r->zAdd($q->key('reserved'), time() - ValkeyJobQueue::STALE_SECONDS - 1, (string) $c);
$got = $q->reserve(1);
t_eq($c, $got[0]['id'] ?? null, 'stale job reserved again');
$q->ack($c);

echo "crash windows (Architecture #118)\n";
// Crash between LMOVE and ZADD in reserve(): id in processing with no reserved score.
$d = $q->push('four');
$r->lMove($q->key('ready'), $q->key('processing'), 'RIGHT', 'LEFT');
t_eq([], $q->reserve(1), 'orphan not handed out at once (could be a live reservation)');
$sc = $r->zRange($q->key('reserved'), 0, -1, ['withscores' => true]);
t_ok(isset($sc[(string) $d]), 'orphan in processing gets a reserved score');
$r->zAdd($q->key('reserved'), time() - ValkeyJobQueue::STALE_SECONDS - 1, (string) $d);
$got = $q->reserve(1);
t_eq($d, $got[0]['id'] ?? null, 'orphan returns to ready once stale and is reserved again');
t_eq(1, (int) $r->lLen($q->key('processing')), 'no duplicate id in processing');
// fail(): release and ZADD delayed are one MULTI, nothing left half-moved.
t_eq(false, $q->fail($d, 'SMTP 550 <ana@casalivre.test> rejected'), 'fail retries');
t_eq(0, (int) $r->lLen($q->key('processing')), 'fail: out of processing');
t_eq(0, (int) $r->zCard($q->key('reserved')), 'fail: out of reserved');
t_eq(1, (int) $r->zCard($q->key('delayed')), 'fail: in delayed');
$err = (string) ($r->hGetAll($q->key('job:' . $d))['last_error'] ?? '');
t_ok(!str_contains($err, 'ana@casalivre.test') && str_contains($err, '[email]'), 'last_error masks the address');
// Due delayed id moves to ready exactly once.
$r->zAdd($q->key('delayed'), time() - 1, (string) $d);
$got = $q->reserve(5);
t_eq([$d], array_column($got, 'id'), 'due delayed job reserved once');
t_eq(0, (int) $r->lLen($q->key('ready')), 'no duplicate left in ready');
$q->ack($d);
t_eq(0, (int) $r->lLen($q->key('processing')) + (int) $r->zCard($q->key('reserved')) + (int) $r->exists($q->key('job:' . $d)), 'ack: release and DEL together');

echo "mail cooldown (HMAC key, SET NX EX before the insert, DEL if it fails)\n";
$mq = new ValkeyMailQueue($r);
$mail = 'cd-' . $tag . '@casalivre.test';
$cdKey = $mq->key('cd:' . SqliteMailQueue::cooldownKey('reset', $mail));
$r->del($cdKey);
$beforeSeq = (int) $r->get($mq->key('seq'));
t_eq(true, $mq->push('reset', $mail), 'first push ok');
t_eq(1, (int) $r->exists($cdKey), 'cooldown key exists right after a successful push');
t_ok((int) $r->ttl($cdKey) > 0, 'cooldown key has a TTL');
t_eq(false, $mq->push('reset', $mail), 'second push within cooldown refused');
t_eq(1, (int) $r->get($mq->key('seq')) - $beforeSeq, 'two back-to-back pushes yield exactly one job');
t_ok(!str_contains($cdKey, $mail), 'cooldown key has no address');
// Take our job back out of the real mail queue (a running queue-worker may already have taken it: the
// address does not exist, so it sends nothing).
$id = (string) ($r->lRange($mq->key('ready'), 0, 0)[0] ?? '');
if ($id !== '' && $r->lRem($mq->key('ready'), $id, 1) === 1) {
    $r->del($mq->key('job:' . $id));
}
$r->del($cdKey);
// Insert fails (INCR throws): no cooldown is left behind, so the retry goes through.
$broken = new class () extends Redis {
    public bool $fail = true;

    public function incr($key, $by = 1): Redis|int|false
    {
        if ($this->fail) {
            throw new RedisException('simulated failure');
        }
        return parent::incr($key, $by);
    }
};
$broken->connect((string) getenv('VALKEY_SOCKET'));
$broken->auth([(string) getenv('VALKEY_USER'), trim((string) file_get_contents((string) getenv('VALKEY_PASSWORD_FILE')))]);
$bq = new ValkeyMailQueue($broken);
$threw = false;
try {
    $bq->push('reset', $mail);
} catch (RedisException $e) {
    $threw = true;
}
t_ok($threw, 'push with a failing insert throws');
t_eq(0, (int) $r->exists($cdKey), 'failed insert leaves no cooldown');
$broken->fail = false;
t_eq(true, $bq->push('reset', $mail), 'retry after the failed insert is accepted');
$id = (string) ($r->lRange($mq->key('ready'), 0, 0)[0] ?? '');
if ($id !== '' && $r->lRem($mq->key('ready'), $id, 1) === 1) {
    $r->del($mq->key('job:' . $id));
}
$r->del($cdKey);

echo "cl_app ACL\n";
$denied = static function (callable $f): bool {
    try {
        return $f() === false;
    } catch (RedisException $e) {
        return true;
    }
};
t_ok($denied(fn () => $r->rawCommand('SET', 'x-' . $tag, '1')), 'SET outside cl:* denied');
t_ok($denied(fn () => $r->rawCommand('GET', 'x')), 'GET outside cl:* denied');
t_ok($denied(fn () => $r->rawCommand('KEYS', '*')), 'KEYS denied');
t_ok($denied(fn () => $r->rawCommand('CONFIG', 'GET', '*')), 'CONFIG denied');
t_ok($denied(fn () => $r->rawCommand('FLUSHALL')), 'FLUSHALL denied');
t_ok($denied(fn () => $r->rawCommand('XADD', 'cl:s-' . $tag, '*', 'a', '1')), 'XADD (streams) denied');
t_ok($denied(fn () => $r->rawCommand('EVAL', 'return 1', '0')), 'EVAL denied');
t_done();
