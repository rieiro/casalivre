<?php
// Sprint 3, PR 11 (Etapa 5): e-mail multipart/alternative. Parte texto (a de antes) primeiro, HTML depois, com cabeçalho
// e rodapé do tema e estilo inline. Escape de tudo (ENT_QUOTES | ENT_HTML5), link só sobre APP_URL ou domínio verificado
// de grupo (https), sem recurso externo nem script, nenhum token além dos que já estão no texto, sem injeção de cabeçalho.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/Mailer.php';
require_once CL_ROOT . '/src/MailHtml.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
t_temp_db();
foreach (['APP_ENV', 'SMTP_HOST', 'MAIL_HTML'] as $k) {
    putenv($k);
}
putenv('APP_URL=https://casalivre.example');
$adm = ['id' => 1, 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($adm, 'imob', 'Imob', 'padrao');
$g = GroupRepository::findBySlug('imob');
db()->prepare('INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP), (?, ?, ?, NULL)')
    ->execute([$g['id'], 'imob.example.com', 't1', $g['id'], 'pendente.example.com', 't2']);
$hrefs = static fn (string $html): array => preg_match_all('/href="([^"]*)"/', $html, $m) ? array_map('html_entity_decode', $m[1]) : [];

echo "escape\n";
$xssNome = 'Ana <script>alert(1)</script> "x" \'y\' & <b>';
$xssTitulo = 'Casa <img src=x onerror=alert(2)> &amp;';
$text = "Olá, $xssNome!\r\n\r\nImóvel: $xssTitulo\r\nLink: https://casalivre.example/verificar?token=abc123&x=\"1\"\r\n";
$brand = ['nome' => 'Marca <i>x</i>', 'tokens' => ['--brand' => 'red;background:url(https://evil.example/a.png)', '--ink' => '#123', '--bg' => '#abcdef']];
$html = MailHtml::render('Assunto <script>', $text, $brand);
foreach (['<script', '<img', '<b>', '<i>', '"x"', "'y'"] as $bad) {
    t_ok(!str_contains($html, $bad), "sem '$bad' cru");
}
t_ok(str_contains($html, 'Ana &lt;script&gt;alert(1)&lt;/script&gt; &quot;x&quot; &apos;y&apos; &amp; &lt;b&gt;'), 'nome escapado (ENT_QUOTES | ENT_HTML5)');
t_ok(str_contains($html, 'Casa &lt;img src=x onerror=alert(2)&gt; &amp;amp;'), 'título escapado');
t_ok(str_contains($html, '<title>Assunto &lt;script&gt;</title>') && str_contains($html, 'Marca &lt;i&gt;x&lt;/i&gt;'), 'assunto e nome da marca escapados');
t_ok(str_contains($html, 'background:#003580') && str_contains($html, 'color:#123') && str_contains($html, 'background:#abcdef'), 'cores do tema; cor inválida vira o padrão');
t_eq(['https://casalivre.example/verificar?token=abc123&x=', 'https://casalivre.example/'], $hrefs($html), 'href escapado, só APP_URL (aspas cortam a URL)');

echo "sem recurso externo\n";
foreach (['<img', '<script', '<link', '<style', '<iframe', '<object', '<form', 'url(', '@import', 'javascript:'] as $bad) {
    t_ok(stripos($html, $bad) === false, "sem '$bad'");
}
t_ok(preg_match('/<[^>]*\s(src|srcset|background|on[a-z]+)\s*=/i', $html . MailHtml::render('s', $text, [])) === 0, 'nenhuma tag com src/background/on*');
t_ok(str_contains($html, 'style="'), 'estilo inline');

echo "links\n";
$t2 = "a https://casalivre.example/senha/esqueci.\r\nb http://evil.example/verificar?token=zzz\r\nc https://imob.example.com/g/imob/\r\n"
    . "d https://imob.example.com:8443/x\r\ne http://imob.example.com/x\r\nf https://pendente.example.com/g/imob/\r\n"
    . "g https://user@casalivre.example/x\r\nh javascript:alert(1)\r\ni https://casalivre.example.evil.example/x\r\nj ftp://casalivre.example/x\r\n";
$h2 = MailHtml::render('s', $t2, ['nome' => 'CasaLivre']);
t_eq(['https://casalivre.example/senha/esqueci', 'https://imob.example.com/g/imob/', 'https://casalivre.example/'], $hrefs($h2), 'só APP_URL e https de domínio verificado viram link');
foreach (['evil.example/verificar', 'imob.example.com:8443/x', 'pendente.example.com/g/imob/', 'user@casalivre.example', 'javascript:alert(1)', 'casalivre.example.evil.example', 'ftp://casalivre.example'] as $txt) {
    t_ok(str_contains($h2, $txt), "'$txt' fica como texto");
}
$_SERVER['HTTP_HOST'] = 'evil.example';
t_ok(!MailHtml::isAllowedLink('https://evil.example/x'), 'Host da requisição não vira origem permitida');
unset($_SERVER['HTTP_HOST']);

echo "multipart\n";
[$subject, $body] = Mailer::linkMail('activation', 'https://casalivre.example/verificar?token=' . str_repeat('ab', 16));
$raw = Mailer::message('nao-responda@casalivre.example', 'bia@casalivre.test', $subject, $body, Mailer::htmlPart($subject, $body));
[$head, $rest] = explode("\r\n\r\n", $raw, 2);
t_ok(preg_match('/^Content-Type: multipart\/alternative; boundary="(=_cl_[0-9a-f]{24})"$/m', $head, $bm) === 1, 'Content-Type multipart/alternative com boundary');
t_ok(str_contains($head, 'MIME-Version: 1.0') && !str_contains($head, 'text/plain'), 'cabeçalho só com o multipart');
$parts = array_values(array_filter(array_map('trim', explode('--' . $bm[1], $rest)), static fn ($p) => $p !== '' && $p !== '--'));
t_eq(2, count($parts), 'duas partes');
$dec = static function (string $p): array {
    [$ph, $pb] = explode("\r\n\r\n", $p, 2);
    return [$ph, (string) base64_decode(str_replace(["\r", "\n"], '', $pb), true)];
};
[$h1, $b1] = $dec($parts[0]);
[$hh, $bh] = $dec($parts[1]);
t_ok(str_contains($h1, 'Content-Type: text/plain; charset=UTF-8') && str_contains($h1, 'Content-Transfer-Encoding: base64'), '1ª parte: text/plain (fallback)');
t_eq(str_replace("\r\n", "\n", $body), str_replace("\r\n", "\n", $b1), 'parte texto igual ao texto de antes');
t_ok(str_contains($hh, 'Content-Type: text/html; charset=UTF-8'), '2ª parte: text/html');
t_ok(str_contains($bh, '<!DOCTYPE html>') && str_contains($bh, 'CasaLivre'), 'HTML com cabeçalho da marca');
t_ok(str_ends_with($raw, '--' . $bm[1] . '--'), 'fecha o multipart');
preg_match_all('/token=([0-9a-f]+)/', $b1, $tt);
preg_match_all('/token=([0-9a-f]+)/', $bh, $th);
t_eq(array_values(array_unique($tt[1])), array_values(array_unique($th[1])), 'HTML sem token além do que já está no link do texto');

echo "cabeçalhos\n";
$raw = Mailer::message('nao-responda@casalivre.example', 'bia@casalivre.test', "Oi\r\nBcc: x@evil.example", 'b', '<p>x</p>');
[$head] = explode("\r\n\r\n", $raw, 2);
t_ok(!preg_match('/^Bcc:/mi', $head), 'assunto com CRLF não injeta cabeçalho (assunto codificado)');
$raw2 = Mailer::message('nao-responda@casalivre.example', 'bia@casalivre.test', 's', 'b', null);
t_ok(str_contains($raw2, "Content-Type: text/plain; charset=UTF-8") && !str_contains($raw2, 'multipart'), 'sem HTML: text/plain como antes');
putenv('MAIL_HTML=0');
t_eq(null, Mailer::htmlPart('s', 'b'), 'MAIL_HTML=0: só texto');
putenv('MAIL_HTML');
t_done();
