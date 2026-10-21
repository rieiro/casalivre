<?php
// Cleanup sprint #158 (Arquitetura #167): a lost ack after a successful send. The email went out, then the ack
// fails because the DB connection (SQL queue) or Valkey (QUEUE_DRIVER=valkey) is lost. The worker must not count
// it as a job failure (no fail(), no attempt spent, no crash loop), and the job is sent again once its reservation
// is stale (STALE_SECONDS, 10 min): at-least-once, so the recipient may get the email twice (e.g. a second
// activation or reset token; the newest one wins). PG only.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';

putenv('QUEUE_DRIVER');
putenv('SMTP_HOST');
t_temp_db();
$dsn = (string) getenv('DB_DSN');
$other = new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dir = sys_get_temp_dir() . '/qwack' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
register_shutdown_function(static fn () => @exec('rm -rf ' . escapeshellarg($dir)));
$sent = [];
$afterSend = null;
MailWorker::$deliver = static function (string $type, string $to) use (&$sent, &$afterSend): bool {
    $sent[] = $to;
    if ($afterSend !== null) {
        ($afterSend)();
        $afterSend = null;
    }
    return true;
};
$rows = static fn (): array => $other->query('SELECT status, attempts, last_error FROM mail_queue ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

echo "SQL queue (PG): DB connection lost between the send and the ack\n";
// pg_terminate_backend: PG only. Develop's CI still runs on SQLite (PG-only per #137, #148 removes SQLite): skipped there.
if (!t_is_pg()) {
    echo "  SKIPPED (PG only: set TEST_PG_DSN)\n";
} else {
    $mail = MailQueues::make();
    $mail->push('activation', 'ack@casalivre.test');
    $afterSend = static function () use ($other): void {
        $pid = (int) db()->query('SELECT pg_backend_pid()')->fetchColumn();
        $other->query("SELECT pg_terminate_backend($pid)");
        usleep(100000);
    };
    $err = null;
    try {
        QueueWorkerLoop::tick($mail, null, $dir);
    } catch (Throwable $e) {
        $err = $e;
    }
    t_ok($err !== null && Db::isConnectionError($err), 'the lost ack propagates as a lost connection (worker exits, runit restarts it)');
    t_eq(['ack@casalivre.test'], $sent, 'the email went out once');
    (static fn () => Db::$conn = null)->bindTo(null, Db::class)(); // the restarted worker: new connection
    $mail = MailQueues::make();
    t_eq([['status' => 'processing', 'attempts' => 0, 'last_error' => '']], array_map(static fn ($r) => ['status' => $r['status'], 'attempts' => (int) $r['attempts'], 'last_error' => $r['last_error']], $rows()), 'still reserved, no attempt spent, no error recorded');
    $rep = QueueWorkerLoop::tick($mail, null, $dir)['mail'];
    t_eq([0, 0], [$rep['enviados'], $rep['falhas']], 'restarted worker: ticks fine (no crash loop), job not resent while reserved');
    $other->exec("UPDATE mail_queue SET updated_at = '2000-01-01 00:00:00' WHERE status = 'processing'"); // 10 min later
    $rep = QueueWorkerLoop::tick($mail, null, $dir)['mail'];
    t_eq([1, 0], [$rep['enviados'], $rep['falhas']], 'after STALE_SECONDS: sent again and acked');
    t_eq(['ack@casalivre.test', 'ack@casalivre.test'], $sent, 'at-least-once: the recipient got it twice');
    t_eq(['sent', 0], [$rows()[0]['status'], (int) $rows()[0]['attempts']], 'sent, attempts still 0');
    $rep = QueueWorkerLoop::tick($mail, null, $dir)['mail'];
    t_eq(0, $rep['enviados'], 'no third send');
}

if (!extension_loaded('redis') || (string) getenv('VALKEY_SOCKET') === '') {
    echo "  SKIPPED Valkey part (needs phpredis and VALKEY_SOCKET: run via ops/dev-env/test-pg.sh)\n";
    t_done();
}
echo "Valkey queue: Valkey lost between the send and the ack\n";
$r = Valkey::connect();
$tag = 't' . getmypid() . bin2hex(random_bytes(3));
/** Mail queue under its own name (never the real cl:q:mail); $down makes every Valkey call fail as in an outage. */
$vq = new class ($r, $tag) extends ValkeyJobQueue implements MailQueue {
    public bool $down = false;
    public int $fails = 0;

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
        return ['type', 'recipient'];
    }

    public function push(string $type, string $recipient): bool
    {
        $this->insertJob(['type' => $type, 'recipient' => $recipient]);
        return true;
    }

    private function check(): void
    {
        if ($this->down) {
            throw new RedisException('Connection lost');
        }
    }

    public function reserve(int $limit): array
    {
        $this->check();
        return parent::reserve($limit);
    }

    public function ack(int $id): void
    {
        $this->check();
        parent::ack($id);
    }

    public function fail(int $id, string $error = ''): bool
    {
        $this->fails++;
        $this->check();
        return parent::fail($id, $error);
    }

    public function purge(int $days): int
    {
        $this->check();
        return parent::purge($days);
    }
};
register_shutdown_function(static function () use ($r, $vq): void {
    $r->del([$vq->key('seq'), $vq->key('ready'), $vq->key('processing'), $vq->key('reserved'), $vq->key('delayed'), $vq->key('job:1')]);
});
$sent = [];
$vq->push('reset', 'vk@casalivre.test');
$afterSend = static function () use ($vq): void {
    $vq->down = true;
};
$err = null;
try {
    MailWorker::run($vq, $dir . '/vk.lock');
} catch (Throwable $e) {
    $err = $e;
}
t_ok($err instanceof RedisException, 'the lost ack propagates as RedisException (' . ($err === null ? 'nothing' : $err::class) . ')');
t_eq(0, $vq->fails, 'fail() not called: an outage is not a job failure (no attempt spent)');
t_eq(['vk@casalivre.test'], $sent, 'the email went out once');
$vq->down = false;
t_eq(['1'], $r->lRange($vq->key('processing'), 0, -1), 'job still reserved (in processing)');
$rep = MailWorker::run($vq, $dir . '/vk.lock');
t_eq([0, 0], [$rep['enviados'], $rep['falhas']], 'Valkey back: worker runs, job not resent while reserved');
$r->zAdd($vq->key('reserved'), 1, '1'); // 10 min later: the reservation is stale
$rep = MailWorker::run($vq, $dir . '/vk.lock');
t_eq([1, 0], [$rep['enviados'], $rep['falhas']], 'after STALE_SECONDS: sent again and acked');
t_eq(['vk@casalivre.test', 'vk@casalivre.test'], $sent, 'at-least-once: the recipient got it twice');
t_eq([0, 0, 0], [$r->exists($vq->key('job:1')), $r->lLen($vq->key('processing')), $r->zCard($vq->key('reserved'))], 'acked: job gone from Valkey');
t_eq(0, $vq->fails, 'still no fail() call');
t_done();
