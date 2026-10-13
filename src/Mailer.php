<?php

declare(strict_types=1);

/**
 * Envio de e-mail por SMTP, sem dependências.
 *
 * Configuração (.env):
 *  SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_SECURE (starttls | tls | none),
 *  SMTP_CA_FILE (opcional, CA extra para validar o certificado), SMTP_TIMEOUT (s, padrão 10).
 *  - starttls (padrão, porta 587): STARTTLS obrigatório; se o servidor não oferecer, aborta.
 *  - tls (porta 465): TLS implícito.
 *  - none: sem criptografia; só aceito para host local (127.0.0.1, ::1, localhost).
 *  O certificado é sempre verificado (nome e cadeia). AUTH PLAIN/LOGIN só depois do TLS.
 *
 * Links de conta: sempre remontados sobre APP_URL (o Host da requisição nunca entra no e-mail).
 * Sem SMTP_HOST: em APP_ENV=dev/local grava em storage/logs/mail-dev.log (0600); fora disso,
 * registra uma falha sem dados no error_log e não envia.
 */
final class Mailer
{
    /** Diretório do mail-dev.log (ajustável em testes). */
    public static ?string $devLogDir = null;

    /** @var list<string> Diálogo SMTP da última tentativa (sem credenciais), para diagnóstico/testes. */
    public static array $lastTranscript = [];

    public static function env(string $key, string $default = ''): string
    {
        if (class_exists('Db')) {
            Db::loadEnv();
        }
        $v = getenv($key);
        return $v === false ? $default : trim((string) $v);
    }

    public static function isDev(): bool
    {
        return in_array(strtolower(self::env('APP_ENV')), ['dev', 'local'], true);
    }

    /** Callback para AccountFlow::$sendLink. Nunca lança: falha de e-mail não derruba o cadastro. */
    public static function sendAccountLink(string $email, string $link): bool
    {
        try {
            $url = self::linkFromAppUrl($link);
            $isReset = str_contains((string) parse_url($url, PHP_URL_PATH), '/senha/');
            $subject = $isReset ? 'CasaLivre: redefinição de senha' : 'CasaLivre: ative sua conta';
            $body = ($isReset
                ? "Recebemos um pedido para redefinir a senha da sua conta CasaLivre.\r\n\r\nAbra o link abaixo para criar uma nova senha:\r\n"
                : "Bem-vindo(a) à CasaLivre!\r\n\r\nAbra o link abaixo para ativar sua conta:\r\n")
                . $url . "\r\n\r\nO link expira em breve. Se você não fez este pedido, ignore este e-mail.\r\n";
            if (self::env('SMTP_HOST') === '') {
                if (self::isDev()) {
                    return self::writeDevLog($email, $subject, $url);
                }
                error_log('CasaLivre: SMTP_HOST não configurado; e-mail de conta não enviado.');
                return false;
            }
            self::send($email, $subject, $body);
            return true;
        } catch (Throwable $e) {
            error_log('CasaLivre: falha ao enviar e-mail (' . $e::class . ').');
            return false;
        }
    }

    /**
     * Remonta o link sobre APP_URL (mantém só caminho e query). Sem APP_URL: em dev usa o link
     * recebido; fora de dev, falha.
     */
    public static function linkFromAppUrl(string $link): string
    {
        $base = rtrim(self::env('APP_URL'), '/');
        if ($base === '') {
            if (self::isDev()) {
                return $link;
            }
            throw new RuntimeException('APP_URL não configurada.');
        }
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string) parse_url($base, PHP_URL_HOST) === '') {
            throw new RuntimeException('APP_URL inválida.');
        }
        $path = (string) (parse_url($link, PHP_URL_PATH) ?? '/');
        $query = parse_url($link, PHP_URL_QUERY);
        return $base . '/' . ltrim($path, '/') . ($query !== null && $query !== false && $query !== '' ? '?' . $query : '');
    }

    private static function writeDevLog(string $email, string $subject, string $url): bool
    {
        $dir = self::$devLogDir ?? dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $file = $dir . '/mail-dev.log';
        $old = umask(0077);
        try {
            $line = json_encode(['ts' => date('c'), 'to' => $email, 'subject' => $subject, 'link' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $ok = file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
            @chmod($file, 0600);
            return $ok;
        } finally {
            umask($old);
        }
    }

    /** Envia um e-mail texto simples. Lança RuntimeException em qualquer falha. */
    public static function send(string $to, string $subject, string $body): void
    {
        $host = self::env('SMTP_HOST');
        $secure = strtolower(self::env('SMTP_SECURE', 'starttls')) ?: 'starttls';
        $port = (int) self::env('SMTP_PORT', $secure === 'tls' ? '465' : '587');
        $user = self::env('SMTP_USER');
        $pass = (string) (getenv('SMTP_PASS') === false ? '' : getenv('SMTP_PASS'));
        $from = self::env('SMTP_FROM');
        $timeout = max(1, (int) self::env('SMTP_TIMEOUT', '10'));

        foreach ([$to, $from] as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n<>]/', $addr) === 1) {
                throw new RuntimeException('Endereço de e-mail inválido (destino ou SMTP_FROM).');
            }
        }
        if (!in_array($secure, ['starttls', 'tls', 'none'], true)) {
            throw new RuntimeException('SMTP_SECURE inválido (use starttls, tls ou none).');
        }
        if ($secure === 'none' && !in_array(strtolower($host), ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new RuntimeException('SMTP_SECURE=none só é aceito para servidor local.');
        }
        $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? '';

        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host, 'SNI_enabled' => true];
        $ca = self::env('SMTP_CA_FILE');
        if ($ca !== '') {
            $ssl['cafile'] = $ca;
        }
        $ctx = stream_context_create(['ssl' => $ssl]);
        $remote = ($secure === 'tls' ? 'tls://' : 'tcp://') . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        self::$lastTranscript = [];
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('Não foi possível conectar ao SMTP.');
        }
        stream_set_timeout($fp, $timeout);
        try {
            self::expect($fp, [220]);
            $ehloName = self::ehloName();
            $caps = self::ehlo($fp, $ehloName);
            if ($secure === 'starttls') {
                if (!preg_grep('/^STARTTLS\b/i', $caps)) {
                    throw new RuntimeException('Servidor SMTP não oferece STARTTLS.');
                }
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('Falha no TLS (STARTTLS).');
                }
                $caps = self::ehlo($fp, $ehloName);
            }
            if ($user !== '') {
                $auth = implode(' ', preg_grep('/^AUTH\b/i', $caps) ?: []);
                if (preg_match('/\bPLAIN\b/i', $auth)) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235], 'AUTH PLAIN ***');
                } elseif (preg_match('/\bLOGIN\b/i', $auth)) {
                    self::cmd($fp, 'AUTH LOGIN', [334]);
                    self::cmd($fp, base64_encode($user), [334], '***');
                    self::cmd($fp, base64_encode($pass), [235], '***');
                } else {
                    throw new RuntimeException('Servidor SMTP sem AUTH PLAIN/LOGIN.');
                }
            }
            self::cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            self::cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            self::write($fp, self::message($from, $to, $subject, $body) . "\r\n.\r\n", '[mensagem]');
            self::expect($fp, [250]);
            self::cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    public static function message(string $from, string $to, string $subject, string $body): string
    {
        $domain = substr($from, strrpos($from, '@') + 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: CasaLivre <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = preg_replace("/\r?\n/", "\r\n", $body) ?? $body;
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private static function ehloName(): string
    {
        $host = (string) parse_url(self::env('APP_URL'), PHP_URL_HOST);
        return preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) === 1 ? $host : 'localhost';
    }

    /** @return list<string> linhas de capacidade do EHLO */
    private static function ehlo($fp, string $name): array
    {
        self::write($fp, 'EHLO ' . $name . "\r\n", 'EHLO ' . $name);
        return self::expect($fp, [250]);
    }

    private static function cmd($fp, string $line, array $codes, ?string $logAs = null): array
    {
        if (preg_match('/[\r\n]/', $line) === 1) {
            throw new RuntimeException('Comando SMTP inválido.');
        }
        self::write($fp, $line . "\r\n", $logAs ?? $line);
        return self::expect($fp, $codes);
    }

    private static function write($fp, string $data, string $logAs): void
    {
        self::$lastTranscript[] = 'C: ' . $logAs;
        $len = strlen($data);
        for ($off = 0; $off < $len;) {
            $n = @fwrite($fp, substr($data, $off));
            if ($n === false || $n === 0) {
                throw new RuntimeException('Conexão SMTP interrompida.');
            }
            $off += $n;
        }
    }

    /** @return list<string> textos das linhas da resposta (sem o código) */
    private static function expect($fp, array $codes): array
    {
        $lines = [];
        while (true) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                throw new RuntimeException('Sem resposta do servidor SMTP.');
            }
            $line = rtrim($line, "\r\n");
            self::$lastTranscript[] = 'S: ' . $line;
            $code = (int) substr($line, 0, 3);
            $lines[] = substr($line, 4);
            if (($line[3] ?? ' ') !== '-') {
                break;
            }
        }
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Resposta SMTP inesperada: ' . $code);
        }
        return $lines;
    }
}
