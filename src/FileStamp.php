<?php

declare(strict_types=1);

/**
 * Identity of a cache's source file, for mtime-based invalidation in long-running processes (WorkerCaches).
 * mtime plus inode and size: filemtime() alone has 1 s resolution, so a rewrite in the same second as the load
 * would go unseen; JsonCache::write replaces the file by rename (new inode), and an in-place rewrite usually
 * changes the size.
 *
 * Those three can still all repeat: two same-size writes in the same second, with ext4 reusing the freed inode
 * (Arquitetura #149). So a file whose mtime is within RACY_SECONDS of now (it may still be rewritten in its mtime
 * second) gets a fourth field, a content hash, which changes on every write (the "racy-git" check). Older files
 * keep the 3-field stamp and are never read (#142: the worker does not re-read unchanged files on every tick).
 * A stamp taken in the racy window always differs from the 3-field stamp taken later, so at worst one extra
 * reload follows a recent write; a write is never missed.
 */
final class FileStamp
{
    /** mtime >= now - RACY_SECONDS: the file can still change without changing mtime, inode or size. */
    public const RACY_SECONDS = 1;

    /** "mtime:inode:size", plus ":<xxh128 of the content>" while the file is racy; null if the file is missing. */
    public static function of(string $file): ?string
    {
        clearstatcache(true, $file);
        $st = @stat($file);
        if ($st === false) {
            return null;
        }
        $stamp = $st['mtime'] . ':' . $st['ino'] . ':' . $st['size'];
        if ($st['mtime'] >= time() - self::RACY_SECONDS) {
            $stamp .= ':' . (string) @hash_file('xxh128', $file);
        }
        return $stamp;
    }
}
