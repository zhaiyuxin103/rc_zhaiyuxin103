<?php

namespace Database\Factories;

use App\Models\Outbound;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outbound>
 */
class OutboundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => fake()->unique()->uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'http_method' => 'POST',
            'target_url' => 'https://example.com/webhooks/notifications',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'payload' => [
                'event' => 'notification.created',
                'resource_id' => fake()->uuid(),
            ],
            'status' => 'queued',
            'attempt_count' => 0,
            'max_attempts' => 5,
            'next_attempt_at' => null,
            'last_attempt_at' => null,
            'succeeded_at' => null,
            'failed_at' => null,
            'last_response_status' => null,
            'last_error' => null,
        ];
    }
}
