<?php

use App\Jobs\FulfillVirtualEsimTopupStepJob;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

uses(Tests\TestCase::class);

it('uses an encrypted unique async job for each virtual topup step', function (): void {
    $job = new FulfillVirtualEsimTopupStepJob(
        '00000000-0000-4000-8000-000000000010',
        '1234567890123456',
        1,
        '00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000002',
    );

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->connection)->toBe('database')
        ->and($job->queue)->toBe('virtual-esim-topups')
        ->and($job->uniqueId())->toBe('virtual-esim-topup:00000000-0000-4000-8000-000000000010:step:1')
        ->and($job->timeout)->toBeLessThan(90);
});
