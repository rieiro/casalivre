<?php
// Cleanup sprint #158: Db::sqlState is the one SQLSTATE read. errorInfo[0] wins when it is a
// 5-character code; getCode() is the fallback (PDO sometimes stores the SQLSTATE only there, and
// sometimes an integer driver code); anything else is null. The four predicates keep their literals.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/GroupRepository.php';
require_once CL_ROOT . '/src/Acoes/ThemeSet.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/** @param list<mixed>|null $errorInfo */
function t_pdo(string $message, mixed $code = 0, ?array $errorInfo = null, ?Throwable $previous = null): PDOException
{
    $e = new PDOException($message, is_int($code) ? $code : 0, $previous);
    if (is_string($code)) {
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, $code);
    }
    if ($errorInfo !== null) {
        $e->errorInfo = $errorInfo;
    }
    return $e;
}

echo "Db::sqlState\n";
t_eq('42P01', Db::sqlState(t_pdo('missing', 14, ['42P01', 14, 'undefined'])), 'errorInfo[0] wins over an integer getCode()');
t_eq('23505', Db::sqlState(t_pdo('dup', '08006', ['23505', 7, 'dup'])), 'errorInfo[0] wins over a different SQLSTATE in getCode()');
t_eq('42P01', Db::sqlState(t_pdo('only code', '42P01')), 'unset errorInfo falls back to getCode()');
t_eq('23505', Db::sqlState(t_pdo('empty info', '23505', ['', 7, 'dup'])), 'empty errorInfo[0] falls back to getCode()');
t_eq('40P01', Db::sqlState(t_pdo('lower', '40P01', ['42p01', 7, 'lower'])), 'lowercase errorInfo[0] falls back to getCode()');
t_eq('55P03', Db::sqlState(t_pdo('short', '55P03', ['4000', 7, 'short'])), 'short errorInfo[0] falls back to getCode()');
t_eq(null, Db::sqlState(t_pdo('ints', 7, [7, 7, 'driver code'])), 'integer errorInfo and integer getCode are null');
t_eq(null, Db::sqlState(t_pdo('nothing', 0)), 'no SQLSTATE is null');
t_eq('HY000', Db::sqlState(t_pdo('general', 0, ['HY000', 7, 'general'])), 'HY000 is a SQLSTATE');

echo "predicates\n";
t_ok(Db::isUndefinedTable(t_pdo('t', 0, ['42P01', 7, 'missing'])), '42P01 via errorInfo is undefined table');
t_ok(Db::isUndefinedTable(t_pdo('t', '42P01')), '42P01 via getCode is undefined table');
t_ok(!Db::isUndefinedTable(t_pdo('t', '42P01', ['23505', 7, 'dup'])), 'a different errorInfo SQLSTATE is not undefined table');
t_ok(!Db::isUndefinedTable(t_pdo('t', 7)), 'no state is not undefined table');

t_ok(GroupRepository::isUniqueViolation(t_pdo('t', 0, ['23505', 7, 'dup'])), '23505 via errorInfo is unique');
t_ok(GroupRepository::isUniqueViolation(t_pdo('t', '23505', ['', 7, ''])), '23505 via getCode is unique');
t_ok(!GroupRepository::isUniqueViolation(t_pdo('t', '23505', ['42P01', 7, 'missing'])), 'errorInfo wins: not a unique violation');
t_ok(!GroupRepository::isUniqueViolation(t_pdo('t', 0)), 'no state is not a unique violation');

$ocupado = new ReflectionMethod(ThemeSet::class, 'ocupado');
foreach (['40001', '40P01', '55P03'] as $state) {
    t_ok($ocupado->invoke(null, t_pdo('lock', 0, [$state, 7, 'busy'])), "$state is ocupado");
}
t_ok(!$ocupado->invoke(null, t_pdo('lock', '40001', ['23505', 7, 'dup'])), 'errorInfo wins: not ocupado');
t_ok($ocupado->invoke(null, t_pdo('lock', '40001', [null, 7, 'busy'])), 'null errorInfo[0] falls back: ocupado');
t_ok(!$ocupado->invoke(null, t_pdo('other', '23505')), 'unique violation is not ocupado');

t_ok(Db::isConnectionError(t_pdo('down', 7, ['08006', 7, 'connection to server failed'])), '08006 is a connection error');
t_ok(Db::isConnectionError(t_pdo('down', '57P02')), '57P02 via getCode is a connection error');
t_ok(Db::isConnectionError(t_pdo('down', '57P01')), '57P01 is a connection error');
t_ok(Db::isConnectionError(t_pdo('down', '57P03')), '57P03 is a connection error');
$inner = t_pdo('SQLSTATE[HY000]: General error: 7 no connection to the server', 0, ['HY000', 7, 'no connection to the server']);
t_ok(Db::isConnectionError(new RuntimeException('wrap', 0, $inner)), 'HY000 dropped session, wrapped');
t_ok(Db::isConnectionError(t_pdo('outer', 0, ['23505', 7, 'dup'], $inner)), 'walks past a non-connection PDOException to the previous one');
t_ok(!Db::isConnectionError(t_pdo('dup', 0, ['23505', 7, 'duplicate key value'])), 'unique violation is not a connection error');
t_ok(!Db::isConnectionError(t_pdo('other', 0, ['HY000', 7, 'General error: 7 some other failure'])), 'other HY000 is not');
t_ok(!Db::isConnectionError(new RuntimeException('SMTP down')), 'non-PDO is not');

t_done();
