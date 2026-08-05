<?php

namespace App\Exceptions;

use RuntimeException;

class IdempotencyKeyConflictException extends RuntimeException
{
    /**
     * Create an exception for an idempotency key reused with another payload.
     */
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct('The Idempotency-Key has already been used with a different request.');
    }
}
