<?php

namespace Database\Factories;

use App\Models\Outbound;
use App\Models\OutboundAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundAttempt>
 */
class OutboundAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outbound_id' => Outbound::factory(),
            'attempt_number' => 1,
            'started_at' => now(),
            'finished_at' => null,
            'outcome' => null,
            'response_status' => null,
            'duration_ms' => null,
            'error_type' => null,
            'error_message' => null,
        ];
    }
}
