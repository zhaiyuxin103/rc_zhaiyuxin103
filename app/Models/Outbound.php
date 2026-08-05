<?php

namespace App\Models;

use App\Enums\OutboundStatus;
use Database\Factories\OutboundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string $request_fingerprint
 * @property string $http_method
 * @property string $target_url
 * @property array<string, string>|null $headers
 * @property array<mixed> $payload
 * @property OutboundStatus $status
 * @property int $attempt_count
 * @property int $max_attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $failed_at
 * @property int|null $last_response_status
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, OutboundAttempt> $attempts
 */
#[Fillable([
    'user_id',
    'idempotency_key',
    'request_fingerprint',
    'http_method',
    'target_url',
    'headers',
    'payload',
    'status',
    'attempt_count',
    'max_attempts',
    'next_attempt_at',
    'last_attempt_at',
    'succeeded_at',
    'failed_at',
    'last_response_status',
    'last_error',
])]
class Outbound extends Model
{
    /** @use HasFactory<OutboundFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the user that submitted the outbound.
     */
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the outbound attempts.
     */
    /**
     * @return HasMany<OutboundAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(OutboundAttempt::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OutboundStatus::class,
            'headers' => 'encrypted:array',
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
