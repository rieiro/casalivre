<?php
// Cleanup sprint #158 (Segurança #88): SqliteMailQueue::push checked the cooldown (SELECT) and then inserted, so
// parallel requests for the same (type, e-mail) could all pass the check and queue several e-mails. N processes push
// the same activation at once, each pausing 200 ms between check and insert: exactly one is queued. Runs on SQLite
// and, with TEST_PG_DSN, on PG.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/MailQueue.php';

const N = 8;
putenv('QUEUE_DRIVER');
$race = static function (string $label): void {
    $email = 'race-' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $go = sys_get_temp_dir() . '/cl-mailrace-' . bin2hex(random_bytes(4));
    db(); // opens the DB first (SQLite: WAL and migrate once), so the children only race on push()
    $procs = [];
    $all = [];
    for ($i = 0; $i < N; $i++) {
        $procs[] = proc_open([PHP_BINARY, __DIR__ . '/mail_cooldown_race_worker.php', $go, $email], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT) ?: null;
        $all[] = $pipes;
    }
    usleep(500000); // every child connected and waiting
    touch($go);
    $out = '';
    $err = '';
    foreach ($procs as $i => $p) {
        $out .= stream_get_contents($all[$i][1]);
        $err .= stream_get_contents($all[$i][2]);
        proc_close($p);
    }
    @unlink($go);
    $st = db()->prepare('SELECT COUNT(*) FROM mail_queue WHERE recipient = ?');
    $st->execute([$email]);
    $rows = (int) $st->fetchColumn();
    t_eq('', trim($err), "$label: children ran without errors");
    t_eq(1, substr_count($out, '1'), "$label: " . N . ' parallel pushes, exactly one accepted (' . $out . ')');
    t_eq(1, $rows, "$label: exactly one mail_queue row");
    $q = new SqliteMailQueue(db());
    t_eq(false, $q->push('activation', $email), "$label: a later push inside the cooldown is still refused");
    t_eq(true, $q->push('reset', $email), "$label: another type for the same e-mail is accepted");
};

t_temp_sqlite();
$race('sqlite');
if (t_is_pg()) {
    (static fn () => Db::$conn = null)->bindTo(null, Db::class)();
    t_temp_db();
    $race('pgsql');
} else {
    echo "  SKIPPED pgsql part (set TEST_PG_DSN)\n";
}
t_done();
