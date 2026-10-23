<?php

declare(strict_types=1);

/**
 * Source-file stamp for the worker file caches (WorkerCaches, #192): filemtime() stored at load; a check reloads
 * when it differs (not "is newer", so a rollback or checkout to an older mtime is seen too). filemtime() has 1 s
 * resolution, so a write in the same second as the load can go unseen until the next change; accepted (Rafael).
 */
final class FileStamp
{
    /** filemtime() without PHP's stat cache; null if the file is missing. */
    public static function of(string $file): ?int
    {
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        return $mtime === false ? null : $mtime;
    }
}
