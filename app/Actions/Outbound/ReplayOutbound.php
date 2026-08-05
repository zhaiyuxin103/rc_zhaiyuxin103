<?php

namespace App\Actions\Outbound;

use App\Enums\OutboundStatus;
use App\Exceptions\OutboundReplayConflictException;
use App\Jobs\DeliverOutbound;
use App\Models\Outbound;
use Illuminate\Support\Facades\DB;

class ReplayOutbound
{
    /**
     * Requeue a failed outbound and preserve its previous attempt history.
     */
    public function handle(Outbound $outbound): Outbound
    {
        return DB::transaction(function () use ($outbound): Outbound {
            $outbound = Outbound::query()
                ->whereKey($outbound->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($outbound->status !== OutboundStatus::Failed) {
                throw new OutboundReplayConflictException;
            }

            $outbound->update([
                'status' => OutboundStatus::Queued,
                'attempt_count' => 0,
                'next_attempt_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);

            DeliverOutbound::dispatch($outbound->getKey())->afterCommit();

            return $outbound;
        });
    }
}
