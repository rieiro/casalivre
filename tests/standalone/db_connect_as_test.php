<?php
// Db::connectAs (revisão do #148): uma construção só do PDO dos logins de papel (LOG_DB_USER, LOG_JOB_DB_USER,
// OPS_DB_USER). Variável de usuário vazia/ausente = credenciais principais (DB_USER/DB_PASSWORD, ignorando a
// variável de senha); senha vazia/ausente = null; mesmas opções (exceções, FETCH_ASSOC). E Db::byteaArrayLiteral.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
t_temp_db();
$mainUser = (string) getenv('DB_USER');
$mainPass = (string) (getenv('DB_PASSWORD') ?: '');
$who = static fn (PDO $p): string => (string) $p->query('SELECT session_user')->fetchColumn();
$state = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
};

echo "fallback para DB_USER/DB_PASSWORD\n";
putenv('T_CA_USER');
putenv('T_CA_PASS=senha-errada-que-nao-pode-ser-usada');
$p = Db::connectAs('T_CA_USER', 'T_CA_PASS');
t_eq($mainUser, $who($p), 'usuário ausente: conecta como DB_USER (e com DB_PASSWORD, não com a senha da variável)');
putenv('T_CA_USER=');
t_eq($mainUser, $who(Db::connectAs('T_CA_USER', 'T_CA_PASS')), 'usuário vazio: idem');
t_eq([PDO::ERRMODE_EXCEPTION, PDO::FETCH_ASSOC], [$p->getAttribute(PDO::ATTR_ERRMODE), $p->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)], 'opções: exceções e FETCH_ASSOC');
t_eq(['x' => 1], $p->query('SELECT 1 AS x')->fetch(), 'fetch() devolve só as chaves por nome');

echo "variável de usuário preenchida\n";
putenv('T_CA_USER=t_ca_papel_inexistente');
putenv('T_CA_PASS');
$err = $state(static fn () => Db::connectAs('T_CA_USER', 'T_CA_PASS'));
t_ok($err !== null && str_contains($err, 't_ca_papel_inexistente'), 'usa o login da variável (papel inexistente: erro com o nome dele)');
putenv("T_CA_USER=$mainUser");
putenv('T_CA_PASS=' . $mainPass);
t_eq($mainUser, $who(Db::connectAs('T_CA_USER', 'T_CA_PASS')), 'login + senha das variáveis: conecta');
putenv('T_CA_PASS=');
if ($mainPass !== '') {
    // Com autenticação por senha (CI): senha vazia vira null e falha; senha errada também (prova que é a da variável).
    t_ok($state(static fn () => Db::connectAs('T_CA_USER', 'T_CA_PASS')) !== null, 'senha vazia = null: login por senha falha');
    putenv('T_CA_PASS=senha-errada');
    t_ok($state(static fn () => Db::connectAs('T_CA_USER', 'T_CA_PASS')) !== null, 'senha da variável é usada (errada: falha)');
} else {
    // peer/trust (env local): senha vazia vira null e conecta.
    t_eq($mainUser, $who(Db::connectAs('T_CA_USER', 'T_CA_PASS')), 'senha vazia = null: conecta (peer/trust)');
}
putenv('T_CA_USER');
putenv('T_CA_PASS');

echo "byteaArrayLiteral\n";
$vals = [random_bytes(32), "\x00\"\\{},", ''];
$st = $p->prepare("SELECT cardinality(a) AS n, array_to_string(ARRAY(SELECT encode(x, 'hex') FROM unnest(a) x), ',') AS hex FROM (SELECT ?::bytea[] AS a) s");
$st->execute([Db::byteaArrayLiteral($vals)]);
$r = $st->fetch();
t_eq([3, implode(',', array_map('bin2hex', $vals))], [(int) $r['n'], (string) $r['hex']], 'ida e volta de 3 valores binários (aspas, barra, chaves, vazio)');
t_eq('{}', Db::byteaArrayLiteral([]), 'lista vazia: {}');
t_done();
