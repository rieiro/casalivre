<?php

declare(strict_types=1);

final class PhotoStorage
{
    public static function directory(int $userId, int $houseId): string
    {
        return dirname(__DIR__) . '/public/uploads/users/' . $userId . '/imoveis/' . $houseId;
    }

    public static function publicPath(int $userId, int $houseId, string $filename): string
    {
        return '/uploads/users/' . $userId . '/imoveis/' . $houseId . '/' . $filename;
    }

    public static function storeUploads(int $userId, int $houseId, array $files, ?array &$created = null): int
    {
        $saved = 0;
        $created = [];
        $dir = self::directory($userId, $houseId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 0;
        }
        $existing = PhotoRepository::count($houseId);
        $limit = class_exists('Settings') ? max(1, Settings::int('max_photos_per_property') ?: 40) : 40;
        $names = $files['name'] ?? [];
        $tmp = $files['tmp_name'] ?? [];
        $errors = $files['error'] ?? [];
        if (!is_array($names)) {
            return 0;
        }
        foreach ($names as $i => $name) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            if ($existing + $saved >= $limit) {
                break;
            }
            $tmpName = (string) ($tmp[$i] ?? '');
            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                continue;
            }
            $size = (int) ($files['size'][$i] ?? 0);
            if ($size < 1 || $size > 16 * 1048576) {
                continue;
            }
            $ext = self::imageExtension($tmpName);
            if ($ext === null) {
                continue;
            }
            $filename = self::randomId() . '.' . $ext;
            if (!move_uploaded_file($tmpName, $dir . '/' . $filename)) {
                continue;
            }
            $row = PhotoRepository::insert(
                $houseId,
                self::publicPath($userId, $houseId, $filename),
                $existing === 0 && $saved === 0 ? 1 : 0,
                $existing + $saved
            );
            $created[] = ['id' => (int) ($row['id'] ?? 0), 'caminho' => (string) ($row['path'] ?? ''), 'capa' => (int) ($row['is_primary'] ?? 0)];
            $saved++;
        }
        return $saved;
    }

    public static function imageExtension(string $path): ?string
    {
        if (!class_exists('finfo')) {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    public static function randomId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    public static function writeGenerated(int $userId, int $houseId, string $filename, string $binary): string
    {
        $dir = self::directory($userId, $houseId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de fotos.');
        }
        file_put_contents($dir . '/' . $filename, $binary);
        return self::publicPath($userId, $houseId, $filename);
    }

    public static function deletePublicFile(string $publicPath): void
    {
        $root = realpath(dirname(__DIR__) . '/public/uploads');
        if ($root === false || !str_starts_with($publicPath, '/uploads/')) {
            return;
        }
        $full = realpath(dirname(__DIR__) . '/public' . $publicPath);
        if ($full === false || !is_file($full) || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        unlink($full);
    }

    public static function deleteHouse(int $userId, int $houseId): void
    {
        self::deleteTree(self::directory($userId, $houseId));
    }

    public static function deleteUser(int $userId): void
    {
        self::deleteTree(dirname(__DIR__) . '/public/uploads/users/' . $userId);
    }

    private static function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::deleteTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
