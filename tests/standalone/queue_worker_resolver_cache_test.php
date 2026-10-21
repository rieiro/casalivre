<?php
// Live bug (2026-10-21): the long-running scripts/queue-worker.php never cleared GrupoResolvedor's verified-domain
// cache, so mail rendered by MailHtml kept linking a group domain after it lost verification. Entries now expire after
// GrupoResolvedor::CACHE_TTL_SECONDS (#142, Rafael): within the TTL the old answer stays; after it, the worker's next
// HTML mail no longer links the unverified domain. Clock injected (GrupoResolvedor::$clock), no sleep.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/conta/Account/AccountFlow.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';
require_once CL_ROOT . '/src/GrupoResolvedor.php';
putenv('APP_URL=https://casalivre.example');
putenv('QUEUE_DRIVER');
putenv('MAIL_HTML');
t_temp_db();
$admin = c_user('active');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $admin['id']);
$adm = ['id' => (int) $admin['id'], 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($adm, 'imob', 'Imob', 'padrao');
$g = GroupRepository::findBySlug('imob');
db()->prepare('INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES (?, ?, ?, ?)')
    ->execute([$g['id'], 'imob.example.com', 't' . bin2hex(random_bytes(3)), '2026-10-01 00:00:00']);

// Delivery stub: renders the real HTML part (Mailer::htmlPart -> MailHtml::render -> isAllowedLink) of a body that
// mentions the group domain, as a worker delivery would; nothing is sent.
$html = [];
MailWorker::$deliver = static function (string $type, string $email) use (&$html): bool {
    $html[] = (string) Mailer::htmlPart('Aviso', "Vitrine: https://imob.example.com/g/imob/\n\nhttps://casalivre.example/");
    return true;
};
$dir = sys_get_temp_dir() . '/qwrc' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$mail = MailQueues::make();
$link = '<a href="https://imob.example.com/g/imob/"';
$now = 1_800_000_000;
GrupoResolvedor::$clock = static function () use (&$now): int {
    return $now;
};

echo "iteration 1: domain verified\n";
t_ok($mail->push('activation', 'a1@casalivre.test'), 'job 1 queued');
$r = QueueWorkerLoop::tick($mail, null, $dir);
t_eq(['mail'], array_keys($r), 'no image queue: mail report only');
t_eq(1, $r['mail']['enviados'] ?? null, 'iteration 1: one mail delivered');
t_ok(str_contains($html[0] ?? '', $link), 'iteration 1: verified domain is a link');

echo "domain loses verification between iterations\n";
db()->exec("UPDATE group_domains SET verified_at = NULL WHERE domain = 'imob.example.com'");
$now += GrupoResolvedor::CACHE_TTL_SECONDS - 1;
t_ok(GrupoResolvedor::doDominio('imob.example.com') !== null, 'within the TTL: the process cache still holds the domain');

echo "iteration 2: TTL expired, domain no longer verified\n";
$now += 1;
t_ok($mail->push('activation', 'a2@casalivre.test'), 'job 2 queued');
$r = QueueWorkerLoop::tick($mail, null, $dir);
t_eq(1, $r['mail']['enviados'] ?? null, 'iteration 2: one mail delivered');
t_ok(isset($html[1]) && !str_contains($html[1], $link), 'iteration 2: unverified domain is no longer a link');
t_ok(str_contains($html[1] ?? '', 'https://imob.example.com/g/imob/'), 'iteration 2: URL kept as plain text');
t_ok(str_contains($html[1] ?? '', '<a href="https://casalivre.example/"'), 'iteration 2: APP_URL still linked');
t_ok(GrupoResolvedor::doDominio('imob.example.com') === null, 'cache now reflects the database');
GrupoResolvedor::$clock = null;

echo "scripts/queue-worker.php runs its loop through QueueWorkerLoop::tick\n";
$src = (string) file_get_contents(CL_ROOT . '/scripts/queue-worker.php');
t_ok(preg_match('/do \{(?:(?!\} while).)*\$reports = QueueWorkerLoop::tick\(/s', $src) === 1, 'loop body is QueueWorkerLoop::tick');
@exec('rm -rf ' . escapeshellarg($dir));
t_done();
