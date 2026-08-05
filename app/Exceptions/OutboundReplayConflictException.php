<?php

namespace App\Exceptions;

use RuntimeException;

class OutboundReplayConflictException extends RuntimeException
{
    /**
     * Create an exception for replaying a delivery that is not failed.
     */
    public function __construct()
    {
        parent::__construct('Only failed deliveries can be replayed.');
    }
}
