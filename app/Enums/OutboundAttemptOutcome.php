<?php

namespace App\Enums;

enum OutboundAttemptOutcome: string
{
    case Succeeded = 'succeeded';
    case RetryableFailure = 'retryable_failure';
    case PermanentFailure = 'permanent_failure';
}
