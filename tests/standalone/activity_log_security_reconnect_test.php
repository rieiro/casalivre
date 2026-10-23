<?php
// ActivityLog::securityPdo is cached for the process. The queue worker pings only the shared Db connection and
// exits when that one is lost; this LOG_DB session is separate and can die on its own (that backend terminated).
// The next security event drops it and inserts once on a new connection. A failed reconnect stays inside
// record() (class name only, the caller does not crash). PG only.
declare(strict_types=1);
require __DIR__ . '/lib.php';
t_temp_db();
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/ActivityLog.php';

function t_security_pdo(): ?PDO
{
    $pdo = (new ReflectionProperty(ActivityLog::class, 'securityPdo'))->getValue();
    return $pdo instanceof PDO ? $pdo : null;
}

function t_security_pid(): int
{
    $pdo = t_security_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('security PDO is not open');
    }
    return (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
}

$dsn = (string) getenv('DB_DSN');
$other = new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$kill = static function (int $pid) use ($other): void {
    t_pg_terminate_backend($other, $pid);
    usleep(100000);
};
$mark = t_log_mark();
$codes = static fn (): array => array_column(t_log_rows($mark), 'code');

echo "terminated security backend: one new connection, the event stored once\n";
ActivityLog::record(ActivityLog::LOGIN_FAIL, ['email' => 'a@b.com']);
$pid1 = t_security_pid();
t_eq('500ms', (string) t_security_pdo()?->query('SHOW statement_timeout')->fetchColumn(), 'cached connection keeps statement_timeout 500ms');
$kill($pid1);
ActivityLog::record(ActivityLog::LOGIN_LOCKED, ['email' => 'a@b.com']);
$pid2 = t_security_pid();
t_ok($pid2 !== $pid1, "reconnected on a new backend ($pid1 -> $pid2)");
t_eq('500ms', (string) t_security_pdo()?->query('SHOW statement_timeout')->fetchColumn(), 'the new connection sets statement_timeout again');
t_eq([ActivityLog::LOGIN_FAIL, ActivityLog::LOGIN_LOCKED], $codes(), 'both events stored; the retried insert did not duplicate the row');
ActivityLog::record(ActivityLog::LOGOUT);
t_eq($pid2, t_security_pid(), 'the new connection stays cached');
t_eq([ActivityLog::LOGIN_FAIL, ActivityLog::LOGIN_LOCKED, ActivityLog::LOGOUT], $codes(), 'a later event reuses it');

echo "reconnect cannot open: record() does not throw, and the dead PDO is not kept\n";
$kill($pid2);
$log = (string) tempnam(sys_get_temp_dir(), 'alsec');
$prevLog = (string) ini_get('error_log');
ini_set('error_log', $log);
putenv('LOG_DB_USER=cl_no_such_log_role');
ActivityLog::record(ActivityLog::EMAIL_VERIFY, ['email' => 'a@b.com']);
putenv('LOG_DB_USER');
ini_set('error_log', $prevLog);
$err = (string) file_get_contents($log);
@unlink($log);
t_eq(null, t_security_pdo(), 'a failed reconnect leaves no cached PDO');
t_eq([ActivityLog::LOGIN_FAIL, ActivityLog::LOGIN_LOCKED, ActivityLog::LOGOUT], $codes(), 'that event is not written');
t_ok(str_contains($err, 'CasaLivre ALERT: activity log write failed (class security, code ' . ActivityLog::EMAIL_VERIFY . ', PDOException).'), 'same ALERT line as any other activity-log failure');
t_ok(!str_contains($err, 'cl_no_such_log_role'), 'the ALERT does not include the role from the connection error');

echo "the next event after a failed reconnect opens a fresh connection\n";
ActivityLog::record(ActivityLog::PASSWORD_RESET, ['email' => 'a@b.com']);
$pid3 = t_security_pid();
t_ok($pid3 !== $pid1 && $pid3 !== $pid2, "fresh backend ($pid3)");
t_eq(ActivityLog::PASSWORD_RESET, $codes()[3] ?? null, 'the following security event is stored');
t_done();
