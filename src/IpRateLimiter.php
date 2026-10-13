<?php

declare(strict_types=1);

/**
 * Limite simples por IP em janela fixa, com estado em arquivos locais
 * (storage/cache/ratelimit, 0700/0600). Usado nos endpoints públicos de geo
 * (/api/cidades, /api/ceps). O limite de login continua no LoginRateLimiter/RateLimit.
 */
final class IpRateLimiter
{
    public static ?string $dir = null;

    /** @return int segundos até liberar (0 = permitido) */
    public static function hit(string $bucket, int $max, int $windowSeconds, ?string $ip = null, ?int $now = null): int
    {
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $now ??= time();
        $dir = self::$dir ?? dirname(__DIR__) . '/storage/cache/ratelimit';
        $old = umask(0077);
        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $file = $dir . '/' . hash('sha256', $bucket . '|' . $ip) . '.json';
            $fh = @fopen($file, 'c+');
            if ($fh === false) {
                return 0; // falha aberta: não derruba a busca de CEP/cidade
            }
            flock($fh, LOCK_EX);
            $data = json_decode((string) stream_get_contents($fh), true);
            $start = (int) ($data['start'] ?? 0);
            $count = (int) ($data['count'] ?? 0);
            if ($now - $start >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }
            $count++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode(['start' => $start, 'count' => $count]));
            flock($fh, LOCK_UN);
            fclose($fh);
            return $count > $max ? max(1, $start + $windowSeconds - $now) : 0;
        } finally {
            umask($old);
        }
    }
}
