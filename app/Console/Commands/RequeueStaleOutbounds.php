<?php

namespace App\Console\Commands;

use App\Enums\OutboundStatus;
use App\Jobs\DeliverOutbound;
use App\Models\Outbound;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('outbounds:requeue-stale')]
#[Description('Requeue pending or stale outbounds.')]
class RequeueStaleOutbounds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $staleBefore = now()->subSeconds((int) config('queue.connections.redis.retry_after', 90));
        $requeued = 0;

        Outbound::query()
            ->whereIn('status', [OutboundStatus::Queued, OutboundStatus::Processing])
            ->where(function (Builder $query) use ($staleBefore): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('status', OutboundStatus::Queued)
                            ->where(function (Builder $query): void {
                                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                            });
                    })
                    ->orWhere(function (Builder $query) use ($staleBefore): void {
                        $query
                            ->where('status', OutboundStatus::Processing)
                            ->where('last_attempt_at', '<=', $staleBefore);
                    });
            })
            ->chunkById(100, function (Collection $outbounds) use (&$requeued): void {
                foreach ($outbounds as $outbound) {
                    if ($outbound->status === OutboundStatus::Processing) {
                        $outbound->update([
                            'status' => OutboundStatus::Queued,
                            'next_attempt_at' => now(),
                            'last_error' => 'The outbound was recovered after a stale worker attempt.',
                        ]);
                    }

                    DeliverOutbound::dispatch($outbound->getKey());
                    $requeued++;
                }
            });

        $this->info("Requeued {$requeued} outbounds.");

        return self::SUCCESS;
    }
}
