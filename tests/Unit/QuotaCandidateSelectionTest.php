<?php

namespace Tests\Unit;

use App\Models\Simcard;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use WeakReference;

final class QuotaCandidateSelectionTest extends TestCase
{
    private function service(): VirtualEsimQuotaService
    {
        return (new ReflectionClass(VirtualEsimQuotaService::class))->newInstanceWithoutConstructor();
    }

    private function card(int $id): Simcard
    {
        $last = match ($id % 7) {
            0 => null,
            1 => 'invalid-date',
            2 => '2026-09-13T11:59:00Z',
            3 => '2026-09-13T11:55:00Z',
            4 => '2026-09-10T12:00:00Z',
            5 => '',
            default => '2026-09-11T12:00:00Z',
        };
        $card = new Simcard();
        $card->id = (string) $id;
        $card->virtual_fulfillment_recipe = [
            'strategy' => $id % 13 === 0 ? 'other' : VirtualEsimQuotaService::STRATEGY,
            'quota' => ['state' => $id % 11 === 0 ? 'SUSPENDED' : 'MONITORING', 'last_checked_at' => $last],
        ];
        return $card;
    }

    public function test_batched_selection_matches_original_order_for_all_limits_and_force_modes(): void
    {
        Carbon::setTestNow('2026-09-13T12:00:00Z');
        try {
            $service = $this->service();
            $select = new ReflectionMethod($service, 'selectQuotaCandidates');
            $cards = collect(range(1, 1200))->map(fn ($id) => $this->card($id));
            foreach ([false, true] as $force) {
                foreach ([-1, 1, 99, 100, 101, 500, 900] as $limit) {
                    // Reference is the previous production selection algorithm.
                    $expected = $cards->filter(fn ($s) => $service->isQuotaCapped($s))
                        ->filter(function ($s) use ($force) {
                            if (strtoupper(trim((string) data_get($s->virtual_fulfillment_recipe, 'quota.state', 'MONITORING'))) === 'SUSPENDED') {
                                return false;
                            }
                            if ($force) {
                                return true;
                            }
                            $last = data_get($s->virtual_fulfillment_recipe, 'quota.last_checked_at');
                            if (! is_string($last) || trim($last) === '') {
                                return true;
                            }
                            try {
                                return Carbon::parse($last)->diffInSeconds(Carbon::now(), true) >= 300;
                            } catch (Throwable) {
                                return true;
                            }
                        })
                        ->sortBy(fn ($s) => (string) data_get($s->virtual_fulfillment_recipe, 'quota.last_checked_at', ''))
                        ->take(max(1, min($limit, 500)))->pluck('id')->all();
                    $actual = $select->invoke($service, $cards->lazy(), $limit, $force)->pluck('id')->all();
                    self::assertSame($expected, $actual, "limit=$limit force=".(int) $force);
                }
            }
            self::assertSame([], $select->invoke($service, [], 100, false)->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_stream_retains_bounded_models_and_reads_all_candidates_before_returning(): void
    {
        $references = [];
        $peak = 0;
        $read = 0;
        $stream = (function () use (&$references, &$peak, &$read) {
            for ($i = 1; $i <= 5000; $i++) {
                $card = $this->card($i);
                $references = array_values(array_filter($references, fn ($ref) => $ref->get() !== null));
                $references[] = WeakReference::create($card);
                $peak = max($peak, count($references));
                $read++;
                yield $card;
            }
        })();
        $service = $this->service();
        $selected = (new ReflectionMethod($service, 'selectQuotaCandidates'))->invoke($service, $stream, 100, true);
        self::assertCount(100, $selected);
        self::assertSame(5000, $read);
        // Lazy chunk iteration can retain the previous batch while building the next.
        self::assertLessThanOrEqual(305, $peak, 'At most the retained 100 plus two batches and iterator overhead');
    }
}
