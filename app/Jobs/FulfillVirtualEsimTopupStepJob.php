<?php

namespace App\Jobs;

use App\Services\VirtualEsimFulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class FulfillVirtualEsimTopupStepJob implements ShouldQueue, ShouldBeEncrypted, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public array $backoff = [5, 15, 30, 60, 120, 300, 600, 1200];

    // Keep this below the default database queue retry_after (90 seconds).
    public int $timeout = 75;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly string $simcardId,
        public readonly string $planId,
        public readonly int $step,
        public readonly ?string $commerceOrderId = null,
        public readonly ?string $commerceOrderItemId = null,
    ) {
        $queue = trim((string) config('esim.virtual_fulfillment.queue', 'default'));
        $this->onQueue($queue !== '' ? $queue : 'default');
    }

    public function uniqueId(): string
    {
        return 'virtual-esim-topup:' . $this->simcardId . ':step:' . $this->step;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('virtual-esim-topups:' . $this->simcardId))
                ->releaseAfter(10)
                ->expireAfter(85),
        ];
    }

    public function handle(VirtualEsimFulfillmentService $service): void
    {
        try {
            $nextStep = $service->fulfillQueuedTopupStep(
                simcardId: $this->simcardId,
                planId: $this->planId,
                step: $this->step,
                commerceOrderId: $this->commerceOrderId,
                commerceOrderItemId: $this->commerceOrderItemId,
            );
        } catch (RuntimeException $exception) {
            $status = (int) $exception->getCode();

            if ($status >= 400 && $status < 500 && $status !== 429) {
                $service->markQueuedTopupsFailed(
                    simcardId: $this->simcardId,
                    step: $this->step,
                    reason: $exception->getMessage(),
                );
                $this->fail($exception);

                return;
            }

            $service->markQueuedTopupsRetrying(
                simcardId: $this->simcardId,
                step: $this->step,
                reason: $exception->getMessage(),
            );

            throw $exception;
        } catch (Throwable $exception) {
            $service->markQueuedTopupsRetrying(
                simcardId: $this->simcardId,
                step: $this->step,
                reason: $exception->getMessage(),
            );

            throw $exception;
        }

        if ($nextStep !== null) {
            self::dispatch(
                $this->simcardId,
                $this->planId,
                $nextStep,
                $this->commerceOrderId,
                $this->commerceOrderItemId,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(VirtualEsimFulfillmentService::class)->markQueuedTopupsFailed(
                simcardId: $this->simcardId,
                step: $this->step,
                reason: $exception?->getMessage() ?? 'Virtual eSIM included top-up queue exhausted its retries.',
            );
        } catch (Throwable) {
            // The original failed job remains the source of truth in failed_jobs.
        }
    }
}
