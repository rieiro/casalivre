<?php

declare(strict_types=1);

final class JsonCache
{
    public static function path(string $name): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/' . $name;
    }

    public static function read(string $name): ?array
    {
        $path = self::path($name);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function write(string $name, array $data, bool $pretty = true): void
    {
        $path = self::path($name);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $flags = JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        file_put_contents($path, json_encode($data, $flags));
    }
}
