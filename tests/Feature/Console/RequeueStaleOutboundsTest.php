<?php

use App\Enums\OutboundStatus;
use App\Jobs\DeliverOutbound;
use App\Models\Outbound;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('stale and due outbounds are requeued for recovery', function () {
    Queue::fake();

    $user = User::factory()->create();
    $queuedOutbound = Outbound::factory()->for($user)->create([
        'status' => OutboundStatus::Queued,
        'next_attempt_at' => now()->subMinute(),
    ]);
    $processingOutbound = Outbound::factory()->for($user)->create([
        'status' => OutboundStatus::Processing,
        'last_attempt_at' => now()->subMinutes(2),
    ]);

    $this->artisan('outbounds:requeue-stale')
        ->assertSuccessful()
        ->expectsOutput('Requeued 2 outbounds.');

    $processingOutbound->refresh();

    expect($processingOutbound->status)->toBe(OutboundStatus::Queued)
        ->and($processingOutbound->next_attempt_at)->not->toBeNull();

    Queue::assertPushed(DeliverOutbound::class, 2);
    expect($queuedOutbound->exists)->toBeTrue();
});
