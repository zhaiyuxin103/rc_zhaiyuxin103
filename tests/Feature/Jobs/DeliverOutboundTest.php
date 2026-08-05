<?php

use App\Actions\Outbound\ReplayOutbound;
use App\Actions\Outbound\SendOutbound;
use App\Enums\OutboundAttemptOutcome;
use App\Enums\OutboundStatus;
use App\Exceptions\RetryableOutboundException;
use App\Jobs\DeliverOutbound;
use App\Models\Outbound;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('a successful external response marks an outbound as succeeded', function () {
    Http::fake([
        'https://example.com/*' => Http::response([], 204),
    ]);

    $outbound = Outbound::factory()->for(User::factory()->create())->create();

    (new DeliverOutbound($outbound->id))->handle(app(SendOutbound::class));

    Http::assertSent(function (Request $request) use ($outbound): bool {
        return $request->header('X-Outbound-ID')[0] === $outbound->id;
    });

    $outbound->refresh();

    expect($outbound->status)->toBe(OutboundStatus::Succeeded)
        ->and($outbound->attempt_count)->toBe(1)
        ->and($outbound->last_response_status)->toBe(204)
        ->and($outbound->attempts()->first()->outcome)->toBe(OutboundAttemptOutcome::Succeeded);
});

test('a server response leaves an outbound queued for retry', function () {
    Http::fake([
        'https://example.com/*' => Http::response(['error' => 'temporary'], 503),
    ]);

    $outbound = Outbound::factory()->for(User::factory()->create())->create();

    expect(fn () => (new DeliverOutbound($outbound->id))->handle(app(SendOutbound::class)))
        ->toThrow(RetryableOutboundException::class);

    $outbound->refresh();

    expect($outbound->status)->toBe(OutboundStatus::Queued)
        ->and($outbound->attempt_count)->toBe(1)
        ->and($outbound->last_response_status)->toBe(503)
        ->and($outbound->next_attempt_at)->not->toBeNull()
        ->and($outbound->attempts()->first()->outcome)->toBe(OutboundAttemptOutcome::RetryableFailure);
});

test('a client error permanently fails an outbound without retrying', function () {
    Http::fake([
        'https://example.com/*' => Http::response(['error' => 'invalid'], 400),
    ]);

    $outbound = Outbound::factory()->for(User::factory()->create())->create();

    (new DeliverOutbound($outbound->id))->handle(app(SendOutbound::class));

    $outbound->refresh();

    expect($outbound->status)->toBe(OutboundStatus::Failed)
        ->and($outbound->attempt_count)->toBe(1)
        ->and($outbound->last_response_status)->toBe(400)
        ->and($outbound->attempts()->first()->outcome)->toBe(OutboundAttemptOutcome::PermanentFailure);
});

test('a replay starts a fresh retry cycle while preserving attempt history', function () {
    Queue::fake();
    Http::fake([
        'https://example.com/*' => Http::response([], 204),
    ]);

    $outbound = Outbound::factory()->for(User::factory()->create())->create([
        'status' => OutboundStatus::Failed,
        'attempt_count' => 5,
        'failed_at' => now(),
    ]);
    $outbound->attempts()->create([
        'attempt_number' => 5,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'outcome' => OutboundAttemptOutcome::PermanentFailure,
        'response_status' => 400,
        'error_type' => 'permanent',
        'error_message' => 'The endpoint rejected the request.',
    ]);

    app(ReplayOutbound::class)->handle($outbound);
    (new DeliverOutbound($outbound->id))->handle(app(SendOutbound::class));

    $outbound->refresh();

    expect($outbound->status)->toBe(OutboundStatus::Succeeded)
        ->and($outbound->attempt_count)->toBe(1)
        ->and($outbound->attempts()->count())->toBe(2)
        ->and($outbound->attempts()->latest('attempt_number')->first()->attempt_number)->toBe(6);
});
