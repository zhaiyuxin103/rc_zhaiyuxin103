<?php

namespace App\Exceptions;

use RuntimeException;

class RetryableOutboundException extends RuntimeException
{
    /**
     * Create an exception for a failure that should be retried by the queue.
     */
    public function __construct(string $message, public readonly ?int $responseStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
