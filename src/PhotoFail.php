<?php

declare(strict_types=1);

/** Short reason codes stored on photos.fail_reason and returned for a rejected upload. */
final class PhotoFail
{
    public const INVALID_TYPE = 'invalid_type';
    public const TOO_MANY_PIXELS = 'too_many_pixels';
    public const CORRUPT = 'corrupt';
    public const MISSING_ORIGINAL = 'missing_original';
    public const STUCK = 'stuck';
    public const QUEUE = 'queue';
    public const RETRIES = 'retries';

    public const WRONG_TYPE = 'wrong_type';
    public const TOO_LARGE = 'too_large';
    public const OVER_LIMIT = 'over_limit';
}
