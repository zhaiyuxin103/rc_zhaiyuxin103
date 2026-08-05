<?php

namespace App\Models;

use App\Enums\OutboundAttemptOutcome;
use Database\Factories\OutboundAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $outbound_id
 * @property int $attempt_number
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property OutboundAttemptOutcome|null $outcome
 * @property int|null $response_status
 * @property int|null $duration_ms
 * @property string|null $error_type
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Outbound $outbound
 */
#[Fillable([
    'outbound_id',
    'attempt_number',
    'started_at',
    'finished_at',
    'outcome',
    'response_status',
    'duration_ms',
    'error_type',
    'error_message',
])]
class OutboundAttempt extends Model
{
    /** @use HasFactory<OutboundAttemptFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the outbound for this attempt.
     */
    /**
     * @return BelongsTo<Outbound, $this>
     */
    public function outbound(): BelongsTo
    {
        return $this->belongsTo(Outbound::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => OutboundAttemptOutcome::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
