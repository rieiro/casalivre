<?php

declare(strict_types=1);

require_once __DIR__ . '/ImageQueue.php';
require_once __DIR__ . '/QueueWorker.php';
require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/PhotoStorage.php';

/**
 * Worker de imagens (cron): para cada foto em processamento, decodifica o original e grava de novo a
 * partir dos pixels com GD (nenhum metadado do arquivo enviado sobrevive: EXIF, GPS, ICC, comentários),
 * corrige a orientação (EXIF Orientation, se a extensão exif existir) e gera WebP em 3 larguras:
 *
 *  - thumb  320 px: miniaturas do painel e da galeria do dono (até ~160 px CSS em telas 2x);
 *  - card   800 px: cartões da busca e grade da galeria pública (~400 px CSS em telas 2x);
 *  - full  1920 px: tela cheia em monitores Full HD / celulares de alta densidade.
 *
 * Nunca amplia (imagem menor que a largura mantém o tamanho original). Depois apaga o original e marca
 * a foto como pronta; após Queues::MAX_ATTEMPTS falhas (backoff da fila de e-mail), marca como falhou
 * e apaga o original.
 */
final class ImageWorker
{
    public const SIZES = ['thumb' => 320, 'card' => 800, 'full' => 1920];
    public const QUALITY = 80;
    public const DEFAULT_BATCH = 20;
    public const RETENTION_DAYS = 7;
    /** Teto de pixels decodificados (protege a memória contra "bombas" de descompressão). */
    public const MAX_PIXELS = 40_000_000;

    public static function run(ImageQueue $queue, string $lockFile, int $batch = self::DEFAULT_BATCH, int $maxSeconds = QueueWorker::MAX_RUN_SECONDS): ?array
    {
        return QueueWorker::run(
            $queue,
            $lockFile,
            static fn (array $job): bool => self::process((int) $job['photo_id']),
            $batch,
            self::RETENTION_DAYS,
            $maxSeconds,
            static function (array $job): void {
                $photo = PhotoRepository::findById((int) $job['photo_id']);
                if ($photo) {
                    PhotoStorage::deleteOriginal((string) ($photo['original'] ?? ''));
                    PhotoRepository::markFailed((int) $photo['id']);
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
        $source = PhotoStorage::originalFile((string) ($photo['original'] ?? ''));
        if ($source === null || !is_file($source)) {
            throw new RuntimeException('Original ausente.');
        }
        $info = @getimagesize($source);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Imagem inválida.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Imagem grande demais.');
        }
        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        };
        if (!$img instanceof GdImage) {
            throw new RuntimeException('Falha ao decodificar.');
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        $img = self::orient($img, $source, $info[2]);
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
        PhotoRepository::markReady($photoId, PhotoStorage::variantPath($uuid, self::SIZES['full']));
        PhotoStorage::deleteOriginal((string) $photo['original']);
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

    private static function writeWebp(GdImage $img, int $maxWidth, string $target): string
    {
        $w = imagesx($img);
        $out = $w > $maxWidth ? imagescale($img, $maxWidth, -1, IMG_BICUBIC) : $img;
        if (!$out instanceof GdImage) {
            throw new RuntimeException('Falha ao redimensionar.');
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Pasta pública de fotos indisponível.');
        }
        $tmp = $target . '.tmp';
        $ok = imagewebp($out, $tmp, self::QUALITY);
        if ($out !== $img) {
            imagedestroy($out);
        }
        if (!$ok || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Falha ao gravar WebP.');
        }
        @chmod($target, 0644);
        return $target;
    }
}
