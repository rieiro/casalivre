<?php

declare(strict_types=1);

/**
 * Identity of a cache's source file, for mtime-based invalidation in long-running processes (WorkerCaches).
 * mtime plus inode and size: filemtime() alone has 1 s resolution, so a rewrite in the same second as the load
 * would go unseen; JsonCache::write replaces the file by rename (new inode), and an in-place rewrite usually
 * changes the size.
 */
final class FileStamp
{
    /** "mtime:inode:size" of $file read fresh (clearstatcache first); null if the file is missing. */
    public static function of(string $file): ?string
    {
        clearstatcache(true, $file);
        $st = @stat($file);
        return $st === false ? null : $st['mtime'] . ':' . $st['ino'] . ':' . $st['size'];
    }
}
