<?php

declare(strict_types=1);

require_once __DIR__ . '/../PhotoFail.php';

function primary_photo(int $propertyId): ?string
{
    return PhotoRepository::primaryPath($propertyId);
}

function photo_fail_text(string $reason): string
{
    $key = match ($reason) {
        PhotoFail::INVALID_TYPE => 'photo.fail_invalid_type',
        PhotoFail::TOO_MANY_PIXELS => 'photo.fail_too_many_pixels',
        PhotoFail::CORRUPT => 'photo.fail_corrupt',
        PhotoFail::MISSING_ORIGINAL => 'photo.fail_missing_original',
        PhotoFail::STUCK => 'photo.fail_stuck',
        PhotoFail::QUEUE => 'photo.fail_queue',
        PhotoFail::RETRIES => 'photo.fail_retries',
    };
    return t($key);
}

/** Caminho de foto seguro para src=: só relativo em /uploads/ (sem "..", esquema ou host); senão o placeholder. */
function photo_url(?string $path): string
{
    $path = (string) $path;
    if (preg_match('#^/uploads/[A-Za-z0-9_/.-]+$#', $path) === 1 && !str_contains($path, '..')) {
        return $path;
    }
    return '/assets/placeholder.svg';
}

/**
 * Variante WebP de uma foto pronta (/uploads/fotos/<uuid>-1920.webp): thumb 320, card 800, full 1920.
 * Caminhos antigos (sem variantes) voltam como estão.
 */
function photo_variant(?string $path, string $size): string
{
    $path = (string) $path;
    $w = ImageWorker::SIZES[$size] ?? null;
    if ($w === null || preg_match('#^(/uploads/fotos/[0-9a-f-]{36})-\d+\.webp$#', $path, $m) !== 1) {
        return $path;
    }
    return $m[1] . '-' . $w . '.webp';
}

/** Atributos srcset/sizes (já escapados, com espaço à frente) para fotos com variantes; '' nas antigas. */
function photo_srcset_attr(?string $path, string $sizes): string
{
    $path = (string) $path;
    if (preg_match('#^/uploads/fotos/[0-9a-f-]{36}-\d+\.webp$#', $path) !== 1) {
        return '';
    }
    $set = [];
    foreach (ImageWorker::SIZES as $name => $w) {
        $set[] = photo_variant($path, $name) . ' ' . $w . 'w';
    }
    return ' srcset="' . e(implode(', ', $set)) . '" sizes="' . e($sizes) . '"';
}
