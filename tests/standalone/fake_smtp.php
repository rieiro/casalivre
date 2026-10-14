<?php
// Servidor SMTP falso para testes: php fake_smtp.php <porta> <plain|starttls|tls> <saida.jsonl> [cert.pem key.pem]
// Aceita AUTH PLAIN/LOGIN com usuário "usuario" e senha "segredo"; grava cada mensagem em JSON por linha.
declare(strict_types=1);
[$_, $port, $mode, $out] = $argv + [null, null, null, null];
$cert = $argv[4] ?? null;
$key = $argv[5] ?? null;
$ssl = $cert ? ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false] : [];
$ctx = stream_context_create(['ssl' => $ssl]);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) {
    fwrite(STDERR, "bind: $errstr\n");
    exit(1);
}
while (true) {
    $c = @stream_socket_accept($srv, 60);
    if (!$c) {
        break;
    }
    stream_set_timeout($c, 10);
    // TLS implícito: handshake depois do accept (a sonda de porta sem TLS só é descartada).
    if ($mode === 'tls' && !@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($c);
        continue;
    }
    $log = [];
    $tls = $mode === 'tls';
    $authed = false;
    $msg = ['from' => null, 'to' => [], 'data' => null, 'auth' => null, 'tls' => $tls, 'ehlo_before_tls' => false];
    $say = static function (string $l) use ($c, &$log): void {
        fwrite($c, $l . "\r\n");
        $log[] = 'S: ' . $l;
    };
    $say('220 fake.smtp ESMTP');
    while (($line = fgets($c)) !== false) {
        $line = rtrim($line, "\r\n");
        $log[] = 'C: ' . $line;
        $verb = strtoupper(strtok($line, ' ') ?: '');
        if ($verb === 'EHLO') {
            $caps = ['250-fake.smtp'];
            if ($mode === 'starttls' && !$tls) {
                $caps[] = '250-STARTTLS';
            }
            if ($tls || $mode === 'plain') {
                $caps[] = '250-AUTH PLAIN LOGIN';
            }
            $caps[] = '250 8BITMIME';
            foreach ($caps as $l) {
                $say($l);
            }
        } elseif ($verb === 'STARTTLS' && $mode === 'starttls') {
            $say('220 go ahead');
            $tls = stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            $msg['tls'] = $tls;
        } elseif ($verb === 'AUTH') {
            $parts = explode(' ', $line);
            if (strtoupper($parts[1] ?? '') === 'PLAIN') {
                $dec = explode("\0", base64_decode($parts[2] ?? ''));
                $ok = ($dec[1] ?? '') === 'usuario' && ($dec[2] ?? '') === 'segredo';
            } else {
                $say('334 VXNlcm5hbWU6');
                $u = base64_decode(rtrim((string) fgets($c)));
                $say('334 UGFzc3dvcmQ6');
                $p = base64_decode(rtrim((string) fgets($c)));
                $ok = $u === 'usuario' && $p === 'segredo';
            }
            $authed = $ok;
            $msg['auth'] = $ok ? 'ok' : 'fail';
            $say($ok ? '235 ok' : '535 bad credentials');
        } elseif ($verb === 'MAIL') {
            $msg['from'] = $line;
            $say('250 ok');
        } elseif ($verb === 'RCPT') {
            $msg['to'][] = $line;
            $say('250 ok');
        } elseif ($verb === 'DATA') {
            $say('354 end with .');
            $data = '';
            while (($l = fgets($c)) !== false && rtrim($l, "\r\n") !== '.') {
                $data .= $l;
            }
            $msg['data'] = $data;
            $say('250 queued');
        } elseif ($verb === 'QUIT') {
            $say('221 bye');
            break;
        } else {
            $say('502 nope');
        }
    }
    fclose($c);
    $msg['log'] = $log;
    file_put_contents($out, json_encode($msg) . "\n", FILE_APPEND | LOCK_EX);
}
