<?php

declare(strict_types=1);

require_once __DIR__ . '/ImageQueue.php';
require_once __DIR__ . '/QueueWorker.php';
require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/PermanentJobFailure.php';
require_once __DIR__ . '/PhotoFail.php';
require_once __DIR__ . '/CalendarSync.php';
require_once __DIR__ . '/OtaPlatformRegistry.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * Worker de imagens (runit, vários processos): para cada foto em processamento, decodifica o original e grava de novo a
 * partir dos pixels com GD (nenhum metadado do arquivo enviado sobrevive: EXIF, GPS, ICC, comentários),
 * corrige a orientação (EXIF Orientation, se a extensão exif existir) e gera WebP em 3 larguras:
 *
 *  - thumb  320 px: miniaturas do painel e da galeria do dono (até ~160 px CSS em telas 2x);
 *  - card   800 px: cartões da busca e grade da galeria pública (~400 px CSS em telas 2x);
 *  - full  1920 px: tela cheia em monitores Full HD / celulares de alta densidade.
 *
 * Nunca amplia (imagem menor que a largura mantém o tamanho original). Depois apaga o original e marca
 * a foto como pronta. Um arquivo inválido, grande demais, corrompido ou sem original falha na primeira
 * tentativa (PermanentJobFailure). As outras falhas seguem o backoff e, em Queues::MAX_ATTEMPTS, marcam
 * a foto como falhou e apagam o original.
 */
final class ImageWorker
{
    public const SIZES = ['thumb' => 320, 'card' => 800, 'full' => 1920];
    public const QUALITY = 80;
    public const DEFAULT_BATCH = 20;
    public const RETENTION_DAYS = 7;
    /** Teto de pixels decodificados (protege a memória contra "bombas" de descompressão). */
    public const MAX_PIXELS = 25_000_000; // ~100-125 MB de RAM no GD (truecolor)

    public static function run(ImageQueue $queue, int $batch = self::DEFAULT_BATCH, int $maxSeconds = QueueWorker::MAX_RUN_SECONDS): ?array
    {
        return QueueWorker::run(
            $queue,
            null,
            static fn (array $job): bool => self::process((int) $job['photo_id']),
            $batch,
            self::RETENTION_DAYS,
            $maxSeconds,
            static function (array $job, Throwable $e): void {
                $photo = PhotoRepository::findById((int) $job['photo_id']);
                if ($photo) {
                    PhotoStorage::deleteOriginal($photo['original']);
                    $reason = $e instanceof PermanentJobFailure ? $e->reason : PhotoFail::RETRIES;
                    PhotoRepository::markFailed((int) $photo['id'], $reason);
                    self::notifyImportPhotoFail($photo, $reason);
                }
            },
            'image_queue: foto'
        );
    }

    /** Processa uma foto. false = nada a fazer (removida ou já processada); lança em falha. */
    public static function process(int $photoId): bool
    {
        $photo = PhotoRepository::findById($photoId);
        if (!$photo || ($photo['status'] ?? '') !== 'processing') {
            return false;
        }
        $original = (string) ($photo['original'] ?? '');
        if ($original === '' && !empty($photo['source_url'])) {
            // OTA URL import: the edit page opened immediately; the CDN download happens here.
            $original = self::materializeRemote($photo);
        }
        $source = PhotoStorage::originalFile($original);
        if ($source === null || !is_file($source)) {
            throw new PermanentJobFailure(PhotoFail::MISSING_ORIGINAL);
        }
        $info = @getimagesize($source);
        if ($info === false) {
            throw new PermanentJobFailure(PhotoFail::CORRUPT);
        }
        if (!in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new PermanentJobFailure(PhotoFail::INVALID_TYPE);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new PermanentJobFailure(PhotoFail::TOO_MANY_PIXELS);
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        };
        if (!$img instanceof GdImage) {
            throw new PermanentJobFailure(PhotoFail::CORRUPT);
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $img = self::orient($img, $source, $info[2]);
        $importId = (int) ($photo['ota_import_id'] ?? 0);
        if ($importId > 0) {
            $phash = self::dhash($img);
            if (PhotoRepository::phashExists((int) $photo['property_id'], $phash, $photoId)) {
                imagedestroy($img);
                PhotoStorage::deleteOriginal($original);
                PhotoRepository::setImportMeta($photoId, $phash, false);
                PhotoRepository::markFailed($photoId, PhotoFail::DUPLICATE);
                self::notifyImportPhotoFail($photo, PhotoFail::DUPLICATE);
                return false;
            }
            PhotoRepository::setImportMeta($photoId, $phash, self::watermarkFlagged($img));
        }
        $uuid = (string) $photo['uuid'];
        $written = [];
        try {
            foreach (self::SIZES as $width) {
                $written[] = self::writeWebp($img, $width, PhotoStorage::variantFile($uuid, $width));
            }
        } catch (Throwable $e) {
            foreach ($written as $f) {
                @unlink($f);
            }
            throw $e;
        }
        imagedestroy($img);
        $ready = PhotoRepository::markReady($photoId, PhotoStorage::variantPath($uuid, self::SIZES['full']));
        PhotoStorage::deleteOriginal($original);
        if (!$ready) {
            // Removida/inativada durante o processamento: não deixa WebP órfão no public/.
            foreach ($written as $f) {
                @unlink($f);
            }
            return false;
        }
        return true;
    }

    private static function orient(GdImage $img, string $source, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($source);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if (in_array($o, [2, 4, 5, 7], true)) {
            imageflip($img, $o === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($o) {
            3, 4 => 180,
            5, 6 => 270, // imagerotate gira no sentido anti-horário
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated instanceof GdImage) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
        return $img;
    }

    /**
     * Reduz para $width px (altura proporcional) só com imagecopyresampled: o imagescale com IMG_BICUBIC,
     * IMG_BICUBIC_FIXED e IMG_WEIGHTED4 retorna false na libgd do Void (e o BICUBIC tem a pior qualidade onde
     * funciona); resampled é determinístico e o de melhor SSIM medido (adr/revisao-pr106-r2.md). O destino é
     * sempre WebP: preserva o alpha (sem blending + savealpha). Lança em falha, com log.
     */
    /** Test knob for the corner overlay heuristic (0..1 edge-density ratio). */
    public static float $watermarkEdgeThreshold = 0.34;

    /** Audit only imported-photo permanent failures (remote CDN, MIME, duplicate phash). */
    private static function notifyImportPhotoFail(array $photo, string $reason): void
    {
        $importId = (int) ($photo['ota_import_id'] ?? 0);
        if ($importId < 1 || !in_array($reason, [PhotoFail::REMOTE, PhotoFail::INVALID_TYPE, PhotoFail::DUPLICATE], true)) {
            return;
        }
        activity(ActivityLog::OTA_IMPORT_PHOTO_FAIL, [
            'imovel_id' => (int) ($photo['property_id'] ?? 0),
            'import_id' => $importId,
            'motivo' => $reason,
        ]);
    }

    /**
     * Downloads a photo source URL into storage/fotos/originais and returns its basename.
     * Host must be an exact OTA image CDN host; the transport is SSRF-safe (CalendarSync).
     */
    private static function materializeRemote(array $photo): string
    {
        $url = (string) ($photo['source_url'] ?? '');
        $importId = (int) ($photo['ota_import_id'] ?? 0);
        $platform = '';
        if ($importId > 0) {
            $stmt = db()->prepare('SELECT platform FROM property_ota_imports WHERE id = ?');
            $stmt->execute([$importId]);
            $platform = (string) ($stmt->fetchColumn() ?: '');
        }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($platform === '' || $host === '' || !OtaPlatformRegistry::isImageHost($platform, $host)) {
            throw new PermanentJobFailure(PhotoFail::REMOTE);
        }
        $result = CalendarSync::fetchImageBytes($url);
        if (isset($result['error'])) {
            // Transient network errors go back to the queue (backoff); policy/format errors are permanent.
            if (in_array((string) $result['error'], ['timeout', 'dns', 'http_5xx'], true)) {
                throw new RuntimeException('Falha temporária ao baixar a foto remota.');
            }
            throw new PermanentJobFailure(PhotoFail::REMOTE);
        }
        $bytes = (string) $result['body'];
        $dir = PhotoStorage::originalsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Pasta de originais indisponível.');
        }
        $tmp = @tempnam($dir, 'cl-remote-');
        if ($tmp === false) {
            throw new RuntimeException('Falha ao preparar a foto remota.');
        }
        $ext = null;
        try {
            if (@file_put_contents($tmp, $bytes) === false) {
                throw new RuntimeException('Falha ao gravar a foto remota.');
            }
            $ext = PhotoStorage::imageExtension($tmp);
        } finally {
            @unlink($tmp);
        }
        if ($ext === null) {
            throw new PermanentJobFailure(PhotoFail::INVALID_TYPE);
        }
        $name = PhotoStorage::storeBytes((string) $photo['uuid'], $ext, $bytes);
        if ($name === null) {
            throw new RuntimeException('Falha ao gravar o original remoto.');
        }
        PhotoRepository::setOriginal((int) $photo['id'], $name);
        return $name;
    }

    /**
     * 64-bit dHash (difference hash) as 16 lowercase hex chars: 9x8 grayscale, left < right per row.
     * Deterministic and GD-only (no Imagick), used to skip duplicated imported photos.
     */
    public static function dhash(GdImage $img): string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $small = imagecreatetruecolor(9, 8);
        if (!$small instanceof GdImage) {
            return str_repeat('0', 16);
        }
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, max(1, $w), max(1, $h));
        imagefilter($small, IMG_FILTER_GRAYSCALE);
        $bits = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = imagecolorat($small, $x, $y) & 0xFF;
                $right = imagecolorat($small, $x + 1, $y) & 0xFF;
                $bits = ($bits << 1) | ($left < $right ? 1 : 0);
            }
        }
        imagedestroy($small);
        return sprintf('%016x', $bits);
    }

    /**
     * Corner overlay heuristic: a logo/watermark creates sharp edges in a corner that are rare in the
     * rest of the photo. Samples the four 22% corner regions and flags when the densest corner's
     * adjacent-luminance edge ratio exceeds self::$watermarkEdgeThreshold.
     */
    public static function watermarkFlagged(GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 40 || $h < 40) {
            return false;
        }
        $cw = max(8, (int) round($w * 0.22));
        $ch = max(8, (int) round($h * 0.22));
        $corners = [
            [0, 0], [max(0, $w - $cw), 0],
            [0, max(0, $h - $ch)], [max(0, $w - $cw), max(0, $h - $ch)],
        ];
        $maxRatio = 0.0;
        foreach ($corners as [$ox, $oy]) {
            $maxRatio = max($maxRatio, self::regionEdgeRatio($img, $ox, $oy, $cw, $ch));
        }
        return $maxRatio >= self::$watermarkEdgeThreshold;
    }

    private static function regionEdgeRatio(GdImage $img, int $ox, int $oy, int $cw, int $ch): float
    {
        $cols = 16;
        $rows = 16;
        $values = [];
        for ($j = 0; $j < $rows; $j++) {
            for ($i = 0; $i < $cols; $i++) {
                $x = $ox + (int) round(($i + 0.5) * $cw / $cols);
                $y = $oy + (int) round(($j + 0.5) * $ch / $rows);
                $rgb = imagecolorat($img, min($x, imagesx($img) - 1), min($y, imagesy($img) - 1));
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $values[$j][$i] = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
            }
        }
        $edges = 0;
        $pairs = 0;
        $threshold = 28;
        for ($j = 0; $j < $rows; $j++) {
            for ($i = 0; $i < $cols; $i++) {
                if ($i + 1 < $cols) {
                    $pairs++;
                    if (abs($values[$j][$i] - $values[$j][$i + 1]) > $threshold) {
                        $edges++;
                    }
                }
                if ($j + 1 < $rows) {
                    $pairs++;
                    if (abs($values[$j][$i] - $values[$j + 1][$i]) > $threshold) {
                        $edges++;
                    }
                }
            }
        }
        return $pairs === 0 ? 0.0 : $edges / $pairs;
    }

    public static function resize(GdImage $img, int $width): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $nh = max(1, (int) round($h * $width / $w));
        $dst = imagecreatetruecolor($width, $nh);
        if (!$dst instanceof GdImage) {
            error_log("CasaLivre: ImageWorker: imagecreatetruecolor({$width}x{$nh}) falhou.");
            throw new RuntimeException('Falha ao redimensionar.');
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        if (!imagecopyresampled($dst, $img, 0, 0, 0, 0, $width, $nh, $w, $h)) {
            imagedestroy($dst);
            error_log("CasaLivre: ImageWorker: imagecopyresampled {$w}x{$h} -> {$width}x{$nh} falhou.");
            throw new RuntimeException('Falha ao redimensionar.');
        }
        return $dst;
    }

    private static function writeWebp(GdImage $img, int $maxWidth, string $target): string
    {
        $out = imagesx($img) > $maxWidth ? self::resize($img, $maxWidth) : $img;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Pasta pública de fotos indisponível.');
        }
        $tmp = $target . '.tmp';
        $ok = imagewebp($out, $tmp, self::QUALITY);
        if ($out !== $img) {
            imagedestroy($out);
        }
        if (!$ok || !@rename($tmp, $target) || !is_file($target) || (int) @filesize($target) <= 12) {
            @unlink($tmp);
            @unlink($target);
            throw new RuntimeException('Falha ao gravar WebP.');
        }
        @chmod($target, 0644);
        return $target;
    }
}
