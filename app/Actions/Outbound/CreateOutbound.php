<?php

namespace App\Actions\Outbound;

use App\Enums\OutboundStatus;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Jobs\DeliverOutbound;
use App\Models\Outbound;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateOutbound
{
    /**
     * Create an outbound or return the existing outbound for the same request.
     *
     * @param array{
     *     idempotency_key: string,
     *     target_url: string,
     *     http_method?: string,
     *     headers?: array<string, string>,
     *     payload: array<mixed>
     * } $attributes
     */
    public function handle(User $user, array $attributes): Outbound
    {
        $attributes = $this->normalizeAttributes($attributes);
        $idempotencyKey = $attributes['idempotency_key'];
        $fingerprint = $this->fingerprint($attributes);

        try {
            return DB::transaction(function () use ($user, $attributes, $idempotencyKey, $fingerprint): Outbound {
                $existingOutbound = Outbound::query()
                    ->where('user_id', $user->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingOutbound !== null) {
                    $this->assertMatchingFingerprint($existingOutbound, $fingerprint);

                    return $existingOutbound;
                }

                $outbound = Outbound::create([
                    'user_id' => $user->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'http_method' => $attributes['http_method'],
                    'target_url' => $attributes['target_url'],
                    'headers' => $attributes['headers'],
                    'payload' => $attributes['payload'],
                    'status' => OutboundStatus::Queued,
                    'next_attempt_at' => now(),
                ]);

                DeliverOutbound::dispatch($outbound->getKey())->afterCommit();

                return $outbound;
            });
        } catch (QueryException $exception) {
            $existingOutbound = Outbound::query()
                ->where('user_id', $user->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingOutbound === null) {
                throw $exception;
            }

            $this->assertMatchingFingerprint($existingOutbound, $fingerprint);

            return $existingOutbound;
        }
    }

    /**
     * Normalize the attributes used for persistence and fingerprinting.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     idempotency_key: string,
     *     target_url: string,
     *     http_method: string,
     *     headers: array<string, string>,
     *     payload: array<mixed>
     * }
     */
    private function normalizeAttributes(array $attributes): array
    {
        $headers = [];

        foreach ($attributes['headers'] ?? [] as $name => $value) {
            $headers[strtolower((string) $name)] = $value;
        }

        return [
            'idempotency_key' => $attributes['idempotency_key'],
            'target_url' => $attributes['target_url'],
            'http_method' => strtoupper($attributes['http_method'] ?? 'POST'),
            'headers' => $this->normalizeValue($headers),
            'payload' => $this->normalizeValue($attributes['payload']),
        ];
    }

    /**
     * Build a stable fingerprint for an idempotent outbound request.
     *
     * @param array{
     *     target_url: string,
     *     http_method: string,
     *     headers: array<string, string>,
     *     payload: array<mixed>
     * } $attributes
     */
    private function fingerprint(array $attributes): string
    {
        return hash('sha256', json_encode([
            'target_url' => $attributes['target_url'],
            'http_method' => $attributes['http_method'],
            'headers' => $attributes['headers'],
            'payload' => $attributes['payload'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Reject reuse of an idempotency key with a different request payload.
     */
    private function assertMatchingFingerprint(Outbound $outbound, string $fingerprint): void
    {
        if (! hash_equals($outbound->request_fingerprint, $fingerprint)) {
            throw new IdempotencyKeyConflictException($outbound->idempotency_key);
        }
    }

    /**
     * Normalize nested associative arrays without changing list order.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }
}
