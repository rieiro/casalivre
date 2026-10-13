<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/** Base HTTP: requer servidor em APP_URL (scripts/start.sh). Sem servidor, os testes são pulados. */
abstract class HttpTestCase extends TestCase
{
    protected static string $base;
    protected string $jar;

    public static function setUpBeforeClass(): void
    {
        self::$base = getenv('APP_URL') ?: 'http://127.0.0.1:8080';
    }

    protected function setUp(): void
    {
        $ch = curl_init(self::$base . '/entrar');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        if (curl_exec($ch) === false) {
            self::markTestSkipped('Servidor não disponível em ' . self::$base);
        }
        $this->jar = tempnam(sys_get_temp_dir(), 'cl');
    }

    protected function http(string $m, string $path, ?array $json = null): array
    {
        $ch = curl_init(self::$base . $path);
        $headers = [];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HEADER => true,
                 CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return [curl_getinfo($ch, CURLINFO_HTTP_CODE), substr($raw, 0, $hs), substr($raw, $hs)];
    }
}
