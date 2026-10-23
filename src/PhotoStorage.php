<?php

declare(strict_types=1);

require_once __DIR__ . '/ImageQueue.php';
require_once __DIR__ . '/ImageWorker.php';

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

    /**
     * Upload assíncrono: valida o tipo real (finfo: jpeg/png/webp), grava o original FORA do public/
     * (storage/fotos/originais/<uuid>.<ext>, 0600), cria a foto com status processing e enfileira na
     * ImageQueue. Os WebP públicos (/uploads/fotos/<uuid>-<largura>.webp, sem id de usuário nem de
     * imóvel) são gerados pelo scripts/image-worker.php. O lote abre a fila uma vez (uma conexão
     * Valkey); se ela falhar, o restante do lote é marcado failed sem conectar de novo.
     */
    public static function storeUploads(int $userId, int $houseId, array $files, ?array &$created = null): int
    {
        $saved = 0;
        $created = [];
        $dir = self::originalsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
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
        $queue = null;
        $queueDown = false;
        foreach ($names as $i => $name) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            if ($existing + $saved >= $limit) {
                break;
            }
            $tmpName = (string) ($tmp[$i] ?? '');
            if ($tmpName === '' || !(self::$acceptFile ?? 'is_uploaded_file')($tmpName)) {
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
            $uuid = self::randomId();
            $original = $uuid . '.' . $ext;
            if (!(self::$moveFile ?? 'move_uploaded_file')($tmpName, $dir . '/' . $original)) {
                continue;
            }
            @chmod($dir . '/' . $original, 0600);
            $row = PhotoRepository::insertProcessing($houseId, $uuid, $original, $existing === 0 && $saved === 0 ? 1 : 0, $existing + $saved);
            $queued = self::enqueueOrFail((int) ($row['id'] ?? 0), $queue, $queueDown);
            $created[] = ['id' => (int) ($row['id'] ?? 0), 'estado' => $queued ? 'processing' : 'failed', 'capa' => (int) ($row['is_primary'] ?? 0)];
            $saved++;
        }
        return $saved;
    }

    /**
     * Seeds/scripts: copia um arquivo local para os originais (finfo jpeg/png/webp, 0600), cria a foto em
     * processing e enfileira; o image-worker gera os WebP. Devolve a linha criada ou null se recusado.
     */
    public static function enqueueOriginal(int $houseId, string $sourceFile, int $primary, int $sort): ?array
    {
        $ext = is_file($sourceFile) ? self::imageExtension($sourceFile) : null;
        $dir = self::originalsDir();
        if ($ext === null || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
            return null;
        }
        $uuid = self::randomId();
        $original = $uuid . '.' . $ext;
        if (!@copy($sourceFile, $dir . '/' . $original)) {
            return null;
        }
        @chmod($dir . '/' . $original, 0600);
        $row = PhotoRepository::insertProcessing($houseId, $uuid, $original, $primary, $sort);
        if (!self::enqueueOrFail((int) ($row['id'] ?? 0))) {
            $row['status'] = 'failed';
        }
        return $row;
    }

    /**
     * Pushes the photo to the ImageQueue. $queue and $queueDown are the upload batch: the first call opens
     * one queue (one Valkey connection) and the rest reuse it. After the first failure, later photos in that
     * batch are marked failed and do not connect again. A queue error (e.g. Valkey down) marks the photo
     * failed instead of leaving it in processing with no job; the error is logged without personal data.
     */
    public static function enqueueOrFail(int $photoId, ?ImageQueue &$queue = null, bool &$queueDown = false): bool
    {
        if ($queueDown) {
            self::markEnqueueFailed($photoId);
            return false;
        }
        try {
            $queue ??= (self::$queueFactory ?? ImageQueues::make(...))();
            $queue->push($photoId);
            return true;
        } catch (Throwable $e) {
            $queueDown = true;
            error_log('image_queue: push failed for photo #' . $photoId . ' (' . $e::class . ')');
            self::markEnqueueFailed($photoId);
            return false;
        }
    }

    /** Photo left processing with no job (queue down, or skipped after an earlier failure in the batch). */
    private static function markEnqueueFailed(int $photoId): void
    {
        db()->prepare("UPDATE photos SET status = 'failed' WHERE id = ? AND status = 'processing'")->execute([$photoId]);
    }

    /**
     * Imóvel inativado: apaga os WebP públicos e originais de todas as fotos e marca-as como removed
     * (nunca mais aparecem, nem pelo caminho direto). Não há reativação de imóvel no sistema.
     */
    public static function retireHouse(int $houseId): int
    {
        $stmt = db()->prepare("SELECT * FROM photos WHERE property_id = ? AND status <> 'removed'");
        $stmt->execute([$houseId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $photo) {
            self::deletePhotoFiles($photo);
        }
        db()->prepare("UPDATE photos SET status = 'removed', path = '', original = NULL WHERE property_id = ?")->execute([$houseId]);
        return count($rows);
    }

    /** Testes: substituem is_uploaded_file/move_uploaded_file. @var null|callable */
    public static $acceptFile = null;
    /** @var null|callable */
    public static $moveFile = null;
    /**
     * Testes: substitui ImageQueues::make() (cada chamada abre uma conexão Valkey). Null usa a fábrica real.
     * @var null|callable(): ImageQueue
     */
    public static $queueFactory = null;

    /** Pasta dos originais (fora do public/). */
    public static function originalsDir(): string
    {
        return self::$originals ?? dirname(__DIR__) . '/storage/fotos/originais';
    }

    /** Pasta pública dos WebP. */
    public static function publicDir(): string
    {
        return self::$public ?? dirname(__DIR__) . '/public/uploads/fotos';
    }

    /** Testes: pastas alternativas. */
    public static ?string $originals = null;
    public static ?string $public = null;

    public static function originalFile(string $name): ?string
    {
        return preg_match('/^[0-9a-f-]{36}\.(jpg|png|webp)$/', $name) === 1 ? self::originalsDir() . '/' . $name : null;
    }

    public static function deleteOriginal(string $name): void
    {
        $file = self::originalFile($name);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    public static function variantPath(string $uuid, int $width): string
    {
        return '/uploads/fotos/' . $uuid . '-' . $width . '.webp';
    }

    public static function variantFile(string $uuid, int $width): string
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $uuid) !== 1) {
            throw new InvalidArgumentException('uuid inválido.');
        }
        return self::publicDir() . '/' . $uuid . '-' . $width . '.webp';
    }

    /** Remove todos os arquivos de uma foto (original e WebP), qualquer que seja o status. */
    public static function deletePhotoFiles(array $photo): void
    {
        self::deleteOriginal((string) ($photo['original'] ?? ''));
        $uuid = (string) ($photo['uuid'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/', $uuid) === 1) {
            foreach (ImageWorker::SIZES as $w) {
                @unlink(self::variantFile($uuid, $w));
            }
            return;
        }
        self::deletePublicFile((string) ($photo['path'] ?? ''));
    }

    /** Tipo real pelo conteúdo (finfo): só jpeg, png e webp. */
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
