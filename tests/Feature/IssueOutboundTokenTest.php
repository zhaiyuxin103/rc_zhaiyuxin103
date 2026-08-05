<?php

use App\Enums\OutboundAbility;
use App\Models\User;

test('issues an outbound token for an existing service account', function () {
    $user = User::factory()->create([
        'email' => 'billing-service@example.com',
    ]);

    $this->artisan('outbounds:issue-token', [
        'email' => $user->email,
        '--name' => 'billing-client',
        '--ability' => [
            OutboundAbility::Create->value,
            OutboundAbility::Read->value,
        ],
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Issued outbound token for billing-service@example.com.');

    $token = $user->tokens()->first();

    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('billing-client')
        ->and($token->abilities)->toBe([
            OutboundAbility::Create->value,
            OutboundAbility::Read->value,
        ]);
});

test('can provision a service account when requested', function () {
    $this->artisan('outbounds:issue-token', [
        'email' => 'reporting-service@example.com',
        '--create-user' => true,
        '--user-name' => 'Reporting Service',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Created service account reporting-service@example.com.');

    $user = User::query()->where('email', 'reporting-service@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Reporting Service')
        ->and($user->tokens()->first()->abilities)->toBe([
            OutboundAbility::Create->value,
            OutboundAbility::Read->value,
            OutboundAbility::Replay->value,
        ]);
});

test('rejects an unsupported outbound ability', function () {
    $user = User::factory()->create();

    $this->artisan('outbounds:issue-token', [
        'email' => $user->email,
        '--ability' => ['outbounds:delete'],
    ])
        ->assertFailed()
        ->expectsOutputToContain('Unsupported ability: outbounds:delete');

    expect($user->tokens()->count())->toBe(0);
});

test('requires an existing service account unless provisioning is requested', function () {
    $this->artisan('outbounds:issue-token', [
        'email' => 'missing-service@example.com',
    ])
        ->assertFailed()
        ->expectsOutputToContain('Use --create-user to provision a service account.');

    expect(User::query()->where('email', 'missing-service@example.com')->exists())->toBeFalse();
});
