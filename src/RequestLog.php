<?php

declare(strict_types=1);

require_once __DIR__ . '/Redact.php';
require_once __DIR__ . '/LogPrivacy.php';

/**
 * Log estruturado por requisição da API (JSON por linha), gravado localmente.
 *
 * - request_id: o access_event_id da requisição (UUIDv7 do servidor, ActivityLog::accessEventId()); devolvido no
 *   header X-Request-Id. O X-Request-Id do cliente é ignorado (spec activity_log §13 Q2).
 * - Chaves sensíveis são omitidas; texto livre tem CPF/cartão/e-mail mascarados (no PHP, antes de gravar).
 * - Caminhos com hash de 32 hex (ical_hash) viram {hash}; URLs em texto livre viram [url].
 * - user, user_agent e URLs viram HMAC com LOG_SALT (obrigatório; sem ele são omitidos). IP truncado (/24, /48).
 * - Falhas de escrita: contador RequestLog::$writeFailures + error_log sem dados.
 * - Rotação diária (api-AAAA-MM-DD.log) + corte por tamanho; arquivos antigos são apagados.
 * - Diretório 0700 e arquivos 0600.
 *
 */
final class RequestLog
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Retenção (dias). PENDENTE: política de retenção a confirmar com o Gerente Geral. */
    public const KEEP_DAYS = 14;
    /** Limite de rotação por tamanho (ajustável em testes). */
    public static int $maxBytes = self::MAX_BYTES;

    /** Chaves com URL: query string e fragmento são removidos (fica esquema + host + caminho). */
    private const URL_KEYS = '/(url|ical|link|href|callback|redirect)/i';

    /** Chaves sensíveis: são OMITIDAS do log (a chave e o valor não são gravados). */
    private const SENSITIVE_KEYS = '/(pass(word)?|senha|token|csrf|secret|segredo|authorization|cookie|api[_-]?key|cvv|cvc|card|cartao|cartão|ics_body|cpf|e-?mail|phone|telefone|celular|whats|name|nome|cep|street|rua|logradouro|address|endereco|endereço|birth|nascimento)/i';

    private static ?string $requestId = null;
    private static float $start = 0.0;
    private static bool $started = false;
    public static ?string $dir = null;

    /** @var array{iteracoes:int,linhas_lidas:int}|null One field for a /api/busca: integers only, no filter values. */
    private static ?array $busca = null;

    /** @param int $iteracoes listing queries (0–4) @param int $linhasLidas candidate rows examined */
    public static function setBusca(int $iteracoes, int $linhasLidas): void
    {
        self::$busca = ['iteracoes' => $iteracoes, 'linhas_lidas' => $linhasLidas];
    }

    public static function start(string $path, string $method, array $body): string
    {
        if (self::$started) {
            return (string) self::$requestId;
        }
        self::$started = true;
        self::$busca = null;
        self::$start = microtime(true);
        require_once __DIR__ . '/ActivityLog.php';
        self::$requestId = ActivityLog::accessEventId();
        if (!headers_sent()) {
            header('X-Request-Id: ' . self::$requestId);
        }
        $query = $_GET;
        register_shutdown_function(static function () use ($path, $method, $body, $query): void {
            try {
                $entry = [
                    'ts' => date('c'),
                    'request_id' => self::$requestId,
                    'method' => $method,
                    'path' => self::redactPath($path),
                    'status' => http_response_code() ?: 200,
                    'duration_ms' => (int) round((microtime(true) - self::$start) * 1000),
                    'ip' => LogPrivacy::ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                    'user' => isset($_SESSION['user_id']) ? LogPrivacy::user((int) $_SESSION['user_id']) : null,
                    'user_agent' => LogPrivacy::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
                    'query' => self::queryForLog($path, $query),
                    'body' => self::bodyForLog($body),
                ];
                if (self::$busca !== null) {
                    $entry['busca'] = self::$busca;
                }
                self::write($entry);
            } catch (Throwable) {
                // Log nunca pode derrubar a requisição; sinaliza sem dados.
                self::$writeFailures++;
                error_log('CasaLivre: falha ao gravar log de requisições (total=' . self::$writeFailures . ').');
            }
        });
        return self::$requestId;
    }

    public static function id(): ?string
    {
        return self::$requestId;
    }

    /**
     * Sanitiza recursivamente a entrada: chaves sensíveis são omitidas;
     * nos demais valores, padrões sensíveis em texto livre são mascarados.
     */
    public static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            $n = 0;
            foreach ($value as $k => $v) {
                if (preg_match(self::SENSITIVE_KEYS, (string) $k) === 1) {
                    continue;
                }
                if (++$n > 50) {
                    $out['_truncado'] = true;
                    break;
                }
                $out[$k] = (is_string($v) && preg_match(self::URL_KEYS, (string) $k) === 1) ? self::redactUrl($v) : self::mask($v);
            }
            return $out;
        }
        if (!is_string($value)) {
            return is_scalar($value) || $value === null ? $value : null;
        }
        return self::maskFreeText($value);
    }

    /** Mascara padrões sensíveis em texto livre (CPF, cartão, e-mail). */
    public static function maskFreeText(string $s): string
    {
        $s = mb_substr($s, 0, 500);
        $s = preg_replace('#https?://\S+#i', '[url]', $s) ?? $s;
        $s = preg_replace('/\b\d(?:[ -]?\d){12,18}\b/', '[cartao]', $s) ?? $s;
        $s = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[cpf]', $s) ?? $s;
        $s = preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', static fn ($m) => self::maskEmail($m[0]), $s) ?? $s;
        return $s;
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '***';
        }
        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /**
     * Chaves do corpo cujo VALOR pode ser gravado: só campos operacionais, sem dado pessoal.
     * Texto livre (title, description, comment), nomes, endereço, CEP, datas de nascimento etc.
     * ficam fora: só o nome da chave é gravado (valor null).
     */
    public const BODY_VALUE_ALLOWLIST = [
        'locale', 'layout', 'acao', 'inicio', 'fim', 'day', 'start', 'count', 'foto_id', 'imovel_id',
        'min_nights', 'price', 'nightly_price', 'cleaning_fee', 'max_guests', 'rooms', 'beds', 'bedrooms',
        'amenity', 'calendar_months', 'checkin_time', 'checkout_time', 'noise_from', 'noise_until', 'score',
    ];

    /**
     * Corpo para o log (allowlist, como na query):
     * - chaves sensíveis (SENSITIVE_KEYS): omitidas (nem o nome);
     * - chaves da BODY_VALUE_ALLOWLIST: valor gravado, ainda passando pela máscara;
     * - demais chaves: só o nome, valor null (nada aninhado é gravado).
     */
    public static function bodyForLog(array $body): array
    {
        $out = [];
        $n = 0;
        foreach ($body as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SENSITIVE_KEYS, $key) === 1 || preg_match('/^[A-Za-z0-9_.\-\[\]]{1,40}$/', $key) !== 1) {
                continue;
            }
            if (++$n > 50) {
                $out['_truncado'] = true;
                break;
            }
            $out[$key] = in_array($key, self::BODY_VALUE_ALLOWLIST, true) ? self::mask($value) : null;
        }
        return $out;
    }

    /** Query keys whose value may be stored. Free-text `q` and `localidade` are name-only (value null).
     *  `local_id` is stored only as a positive int4; any other value is omitted (queryForLog). */
    public const QUERY_VALUE_ALLOWLIST = [
        'local_id', 'checkin', 'checkout', 'hospedes', 'raio', 'preco_min', 'preco_max',
        'score_min', 'amenidade', 'pagina', 'ordem', 'locale', 'cal_view',
    ];

    /**
     * Query string para o log:
     * - rotas de iCal (/api/ical/..., /ical/..., *.ics): sempre vazia;
     * - /api/ceps: só nomes de chave (o CEP pode identificar endereço);
     * - demais: valores só para chaves da allowlist (ainda passam pela máscara); outras chaves -> null.
     */
    public static function queryForLog(string $path, array $query): array
    {
        if (str_starts_with($path, '/api/ical/') || str_starts_with($path, '/ical/') || str_ends_with(strtolower($path), '.ics')) {
            return [];
        }
        $allow = $path === '/api/ceps' ? [] : self::QUERY_VALUE_ALLOWLIST;
        $out = [];
        foreach ($query as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z0-9_.\-\[\]]{1,40}$/', $key) !== 1) {
                continue;
            }
            if ($key === 'local_id' && in_array($key, $allow, true)) {
                $id = self::localIdForLog($value);
                if ($id === null) {
                    continue;
                }
                $out[$key] = $id;
                continue;
            }
            $out[$key] = in_array($key, $allow, true) ? self::mask($value) : null;
        }
        return $out;
    }

    /**
     * Positive int4, same shape as SearchQuery::readLocalId. Anything else is not logged:
     * the raw query string must not reach the log.
     */
    private static function localIdForLog(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 2147483647 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }
        $n = (int) $value;
        return $n > 0 && $n <= 2147483647 && (string) $n === $value ? $n : null;
    }

    /** Substitui segmentos de 32 hex (ical_hash) por {hash}. Alinhado com Redact::path. */
    public static function redactPath(string $path): string
    {
        return Redact::path($path);
    }

    /** URL -> host#hmac(url) (sem LOG_SALT: host#-). */
    public static function redactUrl(string $url): string
    {
        return (string) LogPrivacy::url($url);
    }

    /** @deprecated use LogPrivacy::user */
    public static function userRef(int $userId): ?string
    {
        return LogPrivacy::user($userId);
    }

    /** @deprecated use LogPrivacy::ip */
    public static function maskIp(string $ip): string
    {
        return LogPrivacy::ip($ip);
    }

    /** Número de falhas de escrita do log neste processo (sinalização). */
    public static int $writeFailures = 0;

    private static function directory(): string
    {
        return self::$dir ?? dirname(__DIR__) . '/storage/logs/api';
    }

    public static function write(array $entry): void
    {
        $dir = self::directory();
        $old = umask(0077);
        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            @chmod($dir, 0700);
            $file = $dir . '/api-' . date('Y-m-d') . '.log';
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) >= self::$maxBytes) {
                @rename($file, $file . '.' . date('His') . '.' . bin2hex(random_bytes(2)));
            }
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            $new = !is_file($file);
            if ($line === "\n" || @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
                self::$writeFailures++;
                error_log('CasaLivre: falha ao gravar log de requisições (total=' . self::$writeFailures . ').');
                return;
            }
            if ($new) {
                @chmod($file, 0600);
            }
            if (random_int(1, 100) === 1) {
                self::purge($dir);
            }
        } finally {
            umask($old);
        }
    }

    private static function purge(string $dir): void
    {
        $limit = time() - self::KEEP_DAYS * 86400;
        foreach (glob($dir . '/api-*.log*') ?: [] as $f) {
            if (@filemtime($f) < $limit) {
                @unlink($f);
            }
        }
    }
}
