<?php

namespace App\Jobs;

use App\Actions\Outbound\SendOutbound;
use App\Enums\OutboundAttemptOutcome;
use App\Enums\OutboundStatus;
use App\Exceptions\RetryableOutboundException;
use App\Models\Outbound;
use App\Models\OutboundAttempt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DeliverOutbound implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var array<int> */
    public array $backoff = [60, 300, 900, 1800];

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    /**
     * Create a new outbound delivery job.
     */
    public function __construct(public string $outboundId) {}

    /**
     * Get the unique lock key for this outbound.
     */
    public function uniqueId(): string
    {
        return $this->outboundId;
    }

    /**
     * Deliver the outbound and record the attempt outcome.
     */
    public function handle(SendOutbound $sender): void
    {
        $attempt = $this->startAttempt();

        if ($attempt === null) {
            return;
        }

        $outbound = Outbound::query()->find($this->outboundId);

        if ($outbound === null) {
            throw (new ModelNotFoundException)->setModel(Outbound::class, [$this->outboundId]);
        }

        $startedAt = hrtime(true);

        try {
            $response = $sender->handle($outbound);
        } catch (ConnectionException $exception) {
            $this->recordRetryableFailure($outbound, $attempt, null, $exception->getMessage(), $startedAt);

            throw new RetryableOutboundException(
                'The outbound endpoint could not be reached.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            $this->recordRetryableFailure($outbound, $attempt, null, $exception->getMessage(), $startedAt);

            throw new RetryableOutboundException(
                'The outbound failed unexpectedly.',
                previous: $exception,
            );
        }

        if ($response->successful()) {
            $this->recordSuccess($outbound, $attempt, $response->status(), $startedAt);

            return;
        }

        $message = "The outbound endpoint returned HTTP {$response->status()}.";

        if ($this->isRetryableStatus($response->status())) {
            $this->recordRetryableFailure($outbound, $attempt, $response->status(), $message, $startedAt);

            throw new RetryableOutboundException($message, $response->status());
        }

        $this->recordPermanentFailure($outbound, $attempt, $response->status(), $message, $startedAt);
    }

    /**
     * Mark an outbound as permanently failed after queue retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $outbound = Outbound::query()
                ->whereKey($this->outboundId)
                ->lockForUpdate()
                ->first();

            if ($outbound === null || $outbound->status === OutboundStatus::Succeeded) {
                return;
            }

            $outbound->update([
                'status' => OutboundStatus::Failed,
                'next_attempt_at' => null,
                'failed_at' => now(),
                'last_error' => $this->formatError($exception?->getMessage() ?? 'The outbound exceeded its retry limit.'),
            ]);
        });
    }

    /**
     * Reserve the next attempt with a row lock.
     */
    private function startAttempt(): ?OutboundAttempt
    {
        return DB::transaction(function (): ?OutboundAttempt {
            $outbound = Outbound::query()
                ->whereKey($this->outboundId)
                ->lockForUpdate()
                ->first();

            if ($outbound === null || $outbound->status === OutboundStatus::Succeeded) {
                return null;
            }

            if ($outbound->status === OutboundStatus::Failed
                || ($outbound->next_attempt_at?->isFuture() ?? false)) {
                return null;
            }

            $attemptNumber = ((int) $outbound->attempts()->max('attempt_number')) + 1;
            $attemptCount = $outbound->attempt_count + 1;

            if ($attemptCount > $outbound->max_attempts) {
                $outbound->update([
                    'status' => OutboundStatus::Failed,
                    'next_attempt_at' => null,
                    'failed_at' => now(),
                    'last_error' => 'The outbound exceeded its retry limit.',
                ]);

                return null;
            }

            $startedAt = now();

            $outbound->update([
                'status' => OutboundStatus::Processing,
                'attempt_count' => $attemptCount,
                'last_attempt_at' => $startedAt,
                'next_attempt_at' => null,
            ]);

            return $outbound->attempts()->create([
                'attempt_number' => $attemptNumber,
                'started_at' => $startedAt,
            ]);
        });
    }

    /**
     * Record a successful HTTP response.
     */
    private function recordSuccess(Outbound $outbound, OutboundAttempt $attempt, int $status, int $startedAt): void
    {
        DB::transaction(function () use ($outbound, $attempt, $status, $startedAt): void {
            $finishedAt = now();

            $attempt->update([
                'finished_at' => $finishedAt,
                'outcome' => OutboundAttemptOutcome::Succeeded,
                'response_status' => $status,
                'duration_ms' => $this->durationInMilliseconds($startedAt),
            ]);

            $outbound->update([
                'status' => OutboundStatus::Succeeded,
                'next_attempt_at' => null,
                'succeeded_at' => $finishedAt,
                'last_response_status' => $status,
                'last_error' => null,
            ]);
        });
    }

    /**
     * Record a retryable failure and leave the outbound queued.
     */
    private function recordRetryableFailure(Outbound $outbound, OutboundAttempt $attempt, ?int $status, string $message, int $startedAt): void
    {
        DB::transaction(function () use ($outbound, $attempt, $status, $message, $startedAt): void {
            $finishedAt = now();
            $nextAttemptAt = $outbound->attempt_count < $outbound->max_attempts
                ? $finishedAt->copy()->addSeconds($this->retryDelay($outbound->attempt_count))
                : null;

            $attempt->update([
                'finished_at' => $finishedAt,
                'outcome' => OutboundAttemptOutcome::RetryableFailure,
                'response_status' => $status,
                'duration_ms' => $this->durationInMilliseconds($startedAt),
                'error_type' => 'retryable',
                'error_message' => $this->formatError($message),
            ]);

            $outbound->update([
                'status' => OutboundStatus::Queued,
                'next_attempt_at' => $nextAttemptAt,
                'last_response_status' => $status,
                'last_error' => $this->formatError($message),
            ]);
        });
    }

    /**
     * Record a non-retryable HTTP response.
     */
    private function recordPermanentFailure(Outbound $outbound, OutboundAttempt $attempt, int $status, string $message, int $startedAt): void
    {
        DB::transaction(function () use ($outbound, $attempt, $status, $message, $startedAt): void {
            $finishedAt = now();

            $attempt->update([
                'finished_at' => $finishedAt,
                'outcome' => OutboundAttemptOutcome::PermanentFailure,
                'response_status' => $status,
                'duration_ms' => $this->durationInMilliseconds($startedAt),
                'error_type' => 'permanent',
                'error_message' => $this->formatError($message),
            ]);

            $outbound->update([
                'status' => OutboundStatus::Failed,
                'next_attempt_at' => null,
                'failed_at' => $finishedAt,
                'last_response_status' => $status,
                'last_error' => $this->formatError($message),
            ]);
        });
    }

    /**
     * Determine whether the remote response should be retried.
     */
    private function isRetryableStatus(int $status): bool
    {
        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    /**
     * Get the delay after the current attempt.
     */
    private function retryDelay(int $attemptNumber): int
    {
        return $this->backoff[min(max($attemptNumber - 1, 0), count($this->backoff) - 1)];
    }

    /**
     * Get elapsed time in milliseconds.
     */
    private function durationInMilliseconds(int $startedAt): int
    {
        return max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000));
    }

    /**
     * Keep remote error details bounded before persisting them.
     */
    private function formatError(string $message): string
    {
        return Str::limit($message, 2000, '');
    }
}
