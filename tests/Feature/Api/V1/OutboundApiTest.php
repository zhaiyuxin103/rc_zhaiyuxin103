<?php

use App\Enums\OutboundAbility;
use App\Enums\OutboundStatus;
use App\Jobs\DeliverOutbound;
use App\Models\Outbound;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;

function outboundApiToken(User $user, OutboundAbility ...$abilities): string
{
    return $user->createToken(
        'outbound-api-test',
        array_map(fn (OutboundAbility $ability): string => $ability->value, $abilities),
    )->plainTextToken;
}

function outboundPayload(string $resourceId = 'resource-1'): array
{
    return [
        'target_url' => 'https://example.com/webhooks/outbounds',
        'payload' => [
            'event' => 'invoice.created',
            'resource_id' => $resourceId,
        ],
    ];
}

test('unauthenticated clients cannot submit outbounds', function () {
    $response = $this->postJson('/api/v1/outbounds', outboundPayload(), [
        'Idempotency-Key' => 'unauthenticated-request',
    ]);

    $response->assertUnauthorized();
});

test('an outbound request requires an idempotency key', function () {
    Queue::fake();

    $user = User::factory()->create();
    $token = outboundApiToken($user, OutboundAbility::Create);

    $response = $this
        ->withToken($token)
        ->postJson('/api/v1/outbounds', outboundPayload());

    $response
        ->assertUnprocessable()
        ->assertJsonPath('code', 422)
        ->assertJsonPath('error.idempotency_key.0', 'The idempotency key field is required.');
});

test('an outbound is accepted and queued exactly once', function () {
    Queue::fake();

    $user = User::factory()->create();
    $token = outboundApiToken($user, OutboundAbility::Create);

    $response = $this
        ->withToken($token)
        ->withHeader('Idempotency-Key', 'outbound-1')
        ->postJson('/api/v1/outbounds', outboundPayload());

    $response
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.http_method', 'POST');

    $outboundId = $response->json('data.id');

    $this->assertDatabaseHas('outbounds', [
        'id' => $outboundId,
        'user_id' => $user->id,
        'idempotency_key' => 'outbound-1',
        'status' => 'queued',
    ]);

    Queue::assertPushed(DeliverOutbound::class, function (DeliverOutbound $job) use ($outboundId): bool {
        return $job->outboundId === $outboundId;
    });
});

test('repeating an idempotent request returns the original outbound without dispatching another job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $token = outboundApiToken($user, OutboundAbility::Create);
    $headers = [
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => 'outbound-duplicate',
    ];

    $firstResponse = $this->withHeaders($headers)->postJson('/api/v1/outbounds', outboundPayload());
    $secondResponse = $this->withHeaders($headers)->postJson('/api/v1/outbounds', outboundPayload());

    $firstResponse->assertAccepted();
    $secondResponse
        ->assertAccepted()
        ->assertJsonPath('data.id', $firstResponse->json('data.id'));

    $this->assertDatabaseCount('outbounds', 1);
    Queue::assertPushed(DeliverOutbound::class, 1);
});

test('reusing an idempotency key with another payload returns a conflict', function () {
    Queue::fake();

    $user = User::factory()->create();
    $token = outboundApiToken($user, OutboundAbility::Create);
    $headers = [
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => 'outbound-conflict',
    ];

    $this->withHeaders($headers)->postJson('/api/v1/outbounds', outboundPayload('resource-1'))
        ->assertAccepted();

    $response = $this->withHeaders($headers)->postJson('/api/v1/outbounds', outboundPayload('resource-2'));

    $response
        ->assertStatus(409)
        ->assertJsonPath('code', 409);
});

test('a caller can read only its own outbound status', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $outbound = Outbound::factory()->for($owner)->create();

    expect($outbound->user_id)->toBe($owner->id)
        ->and($otherUser->id)->not->toBe($owner->id);

    $ownerToken = outboundApiToken($owner, OutboundAbility::Read);
    $otherToken = outboundApiToken($otherUser, OutboundAbility::Read);

    $this->flushHeaders()
        ->withToken($ownerToken)
        ->getJson(route('api.v1.outbounds.show', $outbound))
        ->assertOk()
        ->assertJsonPath('data.id', $outbound->id);

    Auth::forgetGuards();

    $this->flushHeaders()
        ->withToken($otherToken)
        ->getJson(route('api.v1.outbounds.show', $outbound))
        ->assertForbidden();
});

test('a caller can replay a failed outbound with the replay ability', function () {
    Queue::fake();

    $user = User::factory()->create();
    $outbound = Outbound::factory()->for($user)->create([
        'status' => OutboundStatus::Failed,
        'attempt_count' => 5,
        'failed_at' => now(),
        'last_error' => 'The endpoint was unavailable.',
    ]);
    $token = outboundApiToken($user, OutboundAbility::Replay);

    $response = $this
        ->withToken($token)
        ->postJson(route('api.v1.outbounds.replay', $outbound));

    $response
        ->assertAccepted()
        ->assertJsonPath('data.id', $outbound->id)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.attempt_count', 0);

    $this->assertDatabaseHas('outbounds', [
        'id' => $outbound->id,
        'status' => 'queued',
        'attempt_count' => 0,
        'failed_at' => null,
    ]);

    Queue::assertPushed(DeliverOutbound::class, 1);
});
