<?php

namespace App\Jobs;

use App\Services\VirtualEsimQuotaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EnforceVirtualEsimQuotaJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public array $backoff = [5, 15, 30, 60, 120, 300, 600, 1200];
    public int $timeout = 75;

    public function __construct(public readonly string $simcardId)
    {
        $connection = trim((string) config('esim.virtual_fulfillment.connection', 'database'));
        $queue = trim((string) config('esim.virtual_fulfillment.quota_queue', 'virtual-esim-quota'));

        $this->onConnection($connection !== '' ? $connection : 'database');
        $this->onQueue($queue !== '' ? $queue : 'virtual-esim-quota');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('virtual-esim-quota:' . $this->simcardId))
                ->releaseAfter(10)
                ->expireAfter(85),
        ];
    }

    public function handle(VirtualEsimQuotaService $service): void
    {
        try {
            $service->enforceSuspend($this->simcardId);
        } catch (Throwable $exception) {
            $service->markSuspendRetrying($this->simcardId, $exception->getMessage());
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(VirtualEsimQuotaService::class)->markSuspendRetrying(
                $this->simcardId,
                $exception?->getMessage() ?? 'Virtual eSIM quota suspension exhausted its retries.',
            );
        } catch (Throwable) {
            // failed_jobs remains the durable failure record.
        }
    }
}
