<?php

declare(strict_types=1);

require_once __DIR__ . '/PhotoFail.php';

/** The image cannot be processed. The queue gives up on the first attempt. */
final class PermanentJobFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
