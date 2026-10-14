<?php
// ExceptionShield/Redact: rota sem token/query no error.log e no activity; exception_ignore_args ativo.
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/Redact.php';

echo "Redact\n";
$h = str_repeat('a1', 16);
t_eq('/ical/{hash}', Redact::route("/ical/$h?x=1&token=abc#f"), 'route: hash redigido e query removida');
t_eq('/api/ical/{hash}.ics', Redact::route("/api/ical/$h.ics"), 'route: .ics');
t_eq('/imoveis/0123456789abcdef/calendario', Redact::route('/imoveis/0123456789abcdef/calendario?a=b'), 'route: hash público (16) mantido');
$t = Redact::text("GET https://x.com/cal/$h.ics?s=SEGREDO falhou");
t_ok(!str_contains($t, $h) && !str_contains($t, 'SEGREDO'), 'text: hash e query removidos');

echo "ExceptionShield via HTTP\n";
t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$errLog = CL_ROOT . '/storage/error.log';
$before = is_file($errLog) ? filesize($errLog) : 0;
// Força exceção: rota existente com banco removido no meio do caminho não é trivial; usamos um handler de teste.
$probe = CL_ROOT . '/public/__shield_probe.php';
file_put_contents($probe, '<?php require_once __DIR__ . "/../src/ExceptionShield.php"; ini_set("zend.exception_ignore_args", "1"); ExceptionShield::register(); function boom(string $segredo) { throw new RuntimeException("falha em /ical/' . $h . '?token=SEGREDO; usuario ana.silva@casalivre.test password=SENHAX1 token: TOKENY2 chave ' . str_repeat('Zq9', 12) . '"); } boom("ARGSEGREDO");');
$r = t_http('GET', $base . "/__shield_probe.php?token=QSEGREDO&h=$h");
@unlink($probe);
t_eq(500, $r['status'], 'resposta 500');
t_ok(!str_contains($r['body'], 'QSEGREDO') && !str_contains($r['body'], $h), 'página de erro sem query/hash');
clearstatcache();
$new = (string) substr((string) @file_get_contents($errLog), $before);
t_ok($new !== '', 'error.log recebeu a entrada');
foreach (['QSEGREDO', 'SEGREDO', 'ARGSEGREDO', $h, 'ana.silva@casalivre.test', 'casalivre.test', 'SENHAX1', 'TOKENY2', str_repeat('Zq9', 12)] as $secret) {
    t_ok(!str_contains($new, $secret), "error.log não contém '$secret'");
}
t_ok(str_contains($new, '{hash}'), 'error.log contém {hash}');
t_ok(str_contains($new, '{email}') && str_contains($new, '{redacted}') && str_contains($new, '{token}'), 'error.log com e-mail, senha/token e token longo mascarados');
t_ok(str_contains($new, 'RuntimeException') || str_contains($new, 'boom'), 'trace ainda útil (função/classe)');

echo "Redact::secrets\n";
$x = Redact::secrets('erro {"email":"joao@x.com","password":"abc123","senha"=>\'s3\'} Authorization: Bearer ' . str_repeat('a', 40));
t_ok(!str_contains($x, 'joao@x.com') && !str_contains($x, 'abc123') && !str_contains($x, "s3'") && !str_contains($x, str_repeat('a', 40)), 'JSON/array/cabeçalho mascarados');

echo "bootstrap\n";
$idx = (string) file_get_contents(CL_ROOT . '/public/index.php');
t_eq(1, substr_count($idx, "ini_set('zend.exception_ignore_args', '1')"), 'index.php ativa zend.exception_ignore_args uma única vez');

t_done();
