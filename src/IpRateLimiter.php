<?php

declare(strict_types=1);

/**
 * Limite simples por IP em janela fixa, com estado em arquivos locais (storage/cache/ratelimit, 0700/0600).
 * Usado pela camada ApiRateLimit do pipeline. IPv6 é agrupado pelo /64. Arquivos mais velhos que MAX_AGE
 * são apagados aqui mesmo (amostragem PURGE_CHANCE e sempre que o teto MAX_FILES é atingido). Com o teto
 * ainda cheio, chave nova recebe 429 (logado). Falha aberta (deixa passar) se o estado não puder ser gravado;
 * o evento vai para o error_log.
 */
final class IpRateLimiter
{
    public const MAX_AGE = 7200;      // >= maior janela usada (1 h)
    public const MAX_FILES = 20000;
    public const PURGE_CHANCE = 100;  // 1 em N chamadas

    public static ?string $dir = null;
    public static int $maxFiles = self::MAX_FILES;
    /** 0 desliga a amostragem (testes). */
    public static int $purgeChance = self::PURGE_CHANCE;

    /** Chave do cliente: IPv4 inteiro; IPv6 pelo prefixo /64. */
    public static function clientKey(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($bin, 12)); // IPv4 mapeado
            }
            return bin2hex(substr($bin, 0, 8)) . '::/64';
        }
        return $ip;
    }

    /** @return int segundos até liberar (0 = permitido) */
    public static function hit(string $bucket, int $max, int $windowSeconds, ?string $ip = null, ?int $now = null): int
    {
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $now ??= time();
        $dir = self::dir();
        $old = umask(0077);
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return self::open('diretório indisponível');
            }
            if (self::$purgeChance > 0 && random_int(1, self::$purgeChance) === 1) {
                self::purge();
            }
            $file = $dir . '/' . hash('sha256', $bucket . '|' . self::clientKey($ip)) . '.json';
            if (!is_file($file) && self::count() >= self::$maxFiles) {
                self::purge();
                if (self::count() >= self::$maxFiles) {
                    // Chave nova com o teto cheio: recusa (429) em vez de criar arquivo; chaves existentes seguem contando.
                    error_log('ip_rate_limiter: teto de arquivos atingido, chave nova recusada');
                    return max(1, $windowSeconds);
                }
            }
            $fh = @fopen($file, 'c+');
            if ($fh === false) {
                return self::open('arquivo de estado indisponível');
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

    /**
     * Consulta sem contar: segundos até liberar (0 = ainda cabe mais um hit na janela). Lê sob LOCK_SH, para não
     * ver o arquivo vazio no meio de uma escrita de hit()/reserve(). Falha aberta (0).
     */
    public static function peek(string $bucket, int $max, int $windowSeconds, ?string $ip = null, ?int $now = null): int
    {
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $now ??= time();
        $file = self::dir() . '/' . hash('sha256', $bucket . '|' . self::clientKey($ip)) . '.json';
        $fh = is_file($file) ? @fopen($file, 'r') : false;
        if ($fh === false) {
            return 0;
        }
        flock($fh, LOCK_SH);
        $data = json_decode((string) stream_get_contents($fh), true);
        flock($fh, LOCK_UN);
        fclose($fh);
        $start = (int) ($data['start'] ?? 0);
        $count = (int) ($data['count'] ?? 0);
        if ($now - $start >= $windowSeconds) {
            return 0;
        }
        return $count >= $max ? max(1, $start + $windowSeconds - $now) : 0;
    }

    /**
     * Confere, age e conta sob o MESMO flock exclusivo do arquivo do balde (sem corrida check-then-act).
     * $action(bool $cabe): bool recebe se ainda cabe uma vaga na janela e devolve se a ação aconteceu de fato;
     * só conta (count++) quando cabia E a ação aconteceu. Chamadas paralelas da mesma chave ficam em fila no
     * flock, então no máximo $max ações por janela. Devolve o retorno de $action.
     * Falha aberta como hit(): sem diretório/arquivo de estado, roda $action(true) sem contar (logado).
     * Chave nova com o teto MAX_FILES cheio: $action(false), como o 429 de hit().
     */
    public static function reserve(string $bucket, int $max, int $windowSeconds, ?string $ip, callable $action, ?int $now = null): bool
    {
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $dir = self::dir();
        $old = umask(0077);
        try {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                self::open('diretório indisponível');
                return (bool) $action(true);
            }
            $file = $dir . '/' . hash('sha256', $bucket . '|' . self::clientKey($ip)) . '.json';
            if (!is_file($file) && self::count() >= self::$maxFiles) {
                self::purge();
                if (self::count() >= self::$maxFiles) {
                    error_log('ip_rate_limiter: teto de arquivos atingido, chave nova recusada');
                    return (bool) $action(false);
                }
            }
            $fh = @fopen($file, 'c+');
            if ($fh === false) {
                self::open('arquivo de estado indisponível');
                return (bool) $action(true);
            }
            flock($fh, LOCK_EX);
            try {
                $now ??= time();
                $data = json_decode((string) stream_get_contents($fh), true);
                $start = (int) ($data['start'] ?? 0);
                $count = (int) ($data['count'] ?? 0);
                if ($now - $start >= $windowSeconds) {
                    $start = $now;
                    $count = 0;
                }
                $cabe = $count < $max;
                $feito = (bool) $action($cabe);
                if ($cabe && $feito) {
                    ftruncate($fh, 0);
                    rewind($fh);
                    fwrite($fh, (string) json_encode(['start' => $start, 'count' => $count + 1]));
                    fflush($fh);
                }
                return $feito;
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        } finally {
            umask($old);
        }
    }

    /** Apaga arquivos de estado sem uso há mais de MAX_AGE segundos. Devolve quantos apagou. */
    public static function purge(?int $now = null): int
    {
        $limit = ($now ?? time()) - self::MAX_AGE;
        $n = 0;
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            $m = @filemtime($f);
            if ($m !== false && $m < $limit && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    private static function dir(): string
    {
        return self::$dir ?? dirname(__DIR__) . '/storage/cache/ratelimit';
    }

    private static function count(): int
    {
        return count(glob(self::dir() . '/*.json', GLOB_NOSORT) ?: []);
    }

    private static function open(string $why): int
    {
        error_log('ip_rate_limiter: falha aberta (' . $why . ')');
        return 0;
    }
}
