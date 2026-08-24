<?php

namespace App\Services;

use App\Services\Esim\EsimProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Resolves a virtual Stellar fixed-data plan into one real BASE package plus
 * zero or more provider-approved TOPUP packages.
 *
 * eSIMAccess top-ups add both data and validity. A recipe is therefore valid
 * only when BOTH totals exactly equal the advertised Stellar plan.
 */
class VirtualEsimPlanResolver
{
    private const MAX_TOPUPS = 10;
    private const FIXED_DATA_TYPE = 1;
    private const MIB = 1048576;

    public function __construct(private readonly EsimProvider $provider) {}

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    public function resolve(array $candidates, int $targetDataBytes, int $targetDurationDays): array
    {
        if ($targetDataBytes <= 0 || $targetDurationDays <= 0) {
            throw new RuntimeException('Virtual plan target data and duration must be positive.', 422);
        }

        $normalizedCandidates = $this->normalizeCandidates($candidates, $targetDataBytes, $targetDurationDays);
        if ($normalizedCandidates === []) {
            throw new RuntimeException('Virtual plan has no eligible real provider base packages.', 422);
        }

        $solutions = [];
        $successfulCatalogQueries = 0;
        $lastRetryableException = null;

        foreach ($normalizedCandidates as $candidate) {
            $baseBytes = (int) $candidate['data_bytes'];
            $baseDays = (int) $candidate['duration_days'];

            // A stale virtual variant may now have an exact real package. Prefer it.
            if ($this->sameData($baseBytes, $targetDataBytes) && $baseDays === $targetDurationDays) {
                $solutions[] = $this->buildRecipe($candidate, [], $targetDataBytes, $targetDurationDays);
                continue;
            }

            // Provider top-ups always add positive validity. A smaller-data base that
            // already consumes all advertised validity can never become an exact recipe.
            if ($baseDays >= $targetDurationDays || $baseBytes >= $targetDataBytes) {
                continue;
            }

            try {
                $providerResponse = $this->topupCatalogForBase((string) $candidate['package_code']);
                $successfulCatalogQueries++;
            } catch (Throwable $exception) {
                if ($this->isRetryableProviderException($exception)) {
                    $lastRetryableException = $exception;
                    break;
                }

                // A removed/unsupported BASE package is simply not a candidate anymore.
                continue;
            }

            $topups = $this->normalizeTopupPackages($providerResponse);
            if ($topups === []) {
                continue;
            }

            $remainingBytes = $targetDataBytes - $baseBytes;
            $remainingDays = $targetDurationDays - $baseDays;
            $combination = $this->findExactTopupCombination($topups, $remainingBytes, $remainingDays);

            if ($combination !== null) {
                $solutions[] = $this->buildRecipe(
                    $candidate,
                    $combination,
                    $targetDataBytes,
                    $targetDurationDays,
                );
            }
        }

        if ($solutions === []) {
            if ($lastRetryableException !== null && $successfulCatalogQueries === 0) {
                throw new RuntimeException(
                    'Virtual plan resolution is temporarily unavailable: ' . $lastRetryableException->getMessage(),
                    503,
                    $lastRetryableException,
                );
            }

            if ($lastRetryableException !== null) {
                // Do not turn a provider outage/rate-limit into a permanent catalog verdict.
                throw new RuntimeException(
                    'Virtual plan resolution is temporarily unavailable: ' . $lastRetryableException->getMessage(),
                    503,
                    $lastRetryableException,
                );
            }

            throw new RuntimeException(
                'No exact provider composition exists for this virtual eSIM plan. Larger-plan fallback is disabled.',
                422,
            );
        }

        usort($solutions, static function (array $left, array $right): int {
            // Fewer writes are operationally safer. Provider TOPUP price breaks ties.
            $leftCount = count($left['topups'] ?? []);
            $rightCount = count($right['topups'] ?? []);
            if ($leftCount !== $rightCount) {
                return $leftCount <=> $rightCount;
            }

            $leftCost = (int) ($left['topup_provider_price_raw_total'] ?? PHP_INT_MAX);
            $rightCost = (int) ($right['topup_provider_price_raw_total'] ?? PHP_INT_MAX);
            if ($leftCost !== $rightCost) {
                return $leftCost <=> $rightCost;
            }

            // With equal writes/cost, use the larger real BASE allowance.
            return (int) ($right['base']['data_bytes'] ?? 0) <=> (int) ($left['base']['data_bytes'] ?? 0);
        });

        return $solutions[0];
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function normalizeCandidates(array $candidates, int $targetDataBytes, int $targetDurationDays): array
    {
        $normalized = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $packageCode = trim((string) ($candidate['package_code'] ?? ''));
            $dataBytes = is_numeric($candidate['data_bytes'] ?? null) ? (int) $candidate['data_bytes'] : 0;
            $durationDays = is_numeric($candidate['duration_days'] ?? null) ? (int) $candidate['duration_days'] : 0;
            $active = filter_var($candidate['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if (
                ! $active
                || $packageCode === ''
                || strlen($packageCode) > 128
                || $dataBytes <= 0
                || $durationDays <= 0
                || $dataBytes > $targetDataBytes + self::MIB
                || $durationDays > $targetDurationDays
            ) {
                continue;
            }

            if (isset($seen[$packageCode])) {
                continue;
            }
            $seen[$packageCode] = true;

            $normalized[] = [
                'package_code' => $packageCode,
                'data_bytes' => $dataBytes,
                'duration_days' => $durationDays,
                'variant_id' => isset($candidate['variant_id']) ? (string) $candidate['variant_id'] : null,
                'name' => isset($candidate['name']) ? (string) $candidate['name'] : null,
                'active' => filter_var($candidate['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        usort($normalized, static function (array $left, array $right) use ($targetDataBytes, $targetDurationDays): int {
            $leftExact = abs((int) $left['data_bytes'] - $targetDataBytes) <= self::MIB
                && (int) $left['duration_days'] === $targetDurationDays;
            $rightExact = abs((int) $right['data_bytes'] - $targetDataBytes) <= self::MIB
                && (int) $right['duration_days'] === $targetDurationDays;

            if ($leftExact !== $rightExact) {
                return $leftExact ? -1 : 1;
            }

            // Repeated/similar top-ups are common. Base plans whose data-share and
            // validity-share are similar are therefore the best candidates to try first.
            $leftShareDelta = abs(
                ((int) $left['data_bytes'] / $targetDataBytes)
                - ((int) $left['duration_days'] / $targetDurationDays)
            );
            $rightShareDelta = abs(
                ((int) $right['data_bytes'] / $targetDataBytes)
                - ((int) $right['duration_days'] / $targetDurationDays)
            );

            if (abs($leftShareDelta - $rightShareDelta) > 0.000001) {
                return $leftShareDelta <=> $rightShareDelta;
            }

            return (int) $right['data_bytes'] <=> (int) $left['data_bytes'];
        });

        return $normalized;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeTopupPackages(array $response): array
    {
        $packages = $this->extractPackageList($response);
        $normalized = [];
        $seen = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $dataType = $this->intFromKeys($package, ['dataType', 'data_type']);
            if ($dataType !== self::FIXED_DATA_TYPE) {
                continue;
            }

            $volume = $this->intFromKeys($package, ['volume', 'totalVolume', 'dataVolume', 'data']);
            $duration = $this->durationInDays($package);
            if ($volume === null || $volume <= 0 || $duration === null || $duration <= 0) {
                continue;
            }

            $providerCode = $this->stringFromKeys($package, ['packageCode', 'package_code', 'code', 'sku']);
            $slug = $this->stringFromKeys($package, ['slug', 'packageSlug', 'package_slug']);
            $lookupCode = $providerCode ?? $slug;
            if ($lookupCode === null || $lookupCode === '') {
                continue;
            }

            $dedupeKey = $lookupCode . '|' . $volume . '|' . $duration;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $normalized[] = [
                'package_code' => $slug ?? $lookupCode,
                'provider_topup_value' => $lookupCode,
                'provider_topup_code' => $providerCode,
                'provider_topup_slug' => $slug,
                'name' => $this->stringFromKeys($package, ['packageName', 'name', 'title']) ?? ($slug ?? $lookupCode),
                'data_bytes' => $volume,
                'duration_days' => $duration,
                'provider_price_raw' => $this->intFromKeys($package, ['price']) ?? 0,
                'currency' => strtoupper($this->stringFromKeys($package, ['currencyCode', 'currency']) ?? 'USD'),
            ];
        }

        return $normalized;
    }

    /**
     * Unbounded-by-package, bounded-by-total-count exact composition search.
     * The provider currently allows at most 10 top-ups on one eSIM.
     *
     * @param array<int,array<string,mixed>> $topups
     * @return array<int,array<string,mixed>>|null
     */
    private function findExactTopupCombination(array $topups, int $targetBytes, int $targetDays): ?array
    {
        if ($targetBytes <= 0 || $targetDays <= 0) {
            return null;
        }

        // Normalize data to MiB to absorb harmless catalog rounding differences while
        // remaining exact at the product-unit level used by all current fixed plans.
        $targetMib = (int) round($targetBytes / self::MIB);
        if ($targetMib <= 0) {
            return null;
        }

        $options = [];
        foreach ($topups as $topup) {
            $mib = (int) round(((int) ($topup['data_bytes'] ?? 0)) / self::MIB);
            $days = (int) ($topup['duration_days'] ?? 0);
            if ($mib <= 0 || $days <= 0 || $mib > $targetMib || $days > $targetDays) {
                continue;
            }

            $topup['_mib'] = $mib;
            $options[] = $topup;
        }

        if ($options === []) {
            return null;
        }

        // state key => ['mib', 'days', 'cost', 'items']
        $states = [
            '0:0' => ['mib' => 0, 'days' => 0, 'cost' => 0, 'items' => []],
        ];

        for ($count = 1; $count <= self::MAX_TOPUPS; $count++) {
            $next = $states;

            foreach ($states as $state) {
                if (count($state['items']) !== $count - 1) {
                    continue;
                }

                foreach ($options as $option) {
                    $mib = (int) $state['mib'] + (int) $option['_mib'];
                    $days = (int) $state['days'] + (int) $option['duration_days'];

                    if ($mib > $targetMib || $days > $targetDays) {
                        continue;
                    }

                    $items = $state['items'];
                    $cleanOption = $option;
                    unset($cleanOption['_mib']);
                    $items[] = $cleanOption;

                    $cost = (int) $state['cost'] + (int) ($option['provider_price_raw'] ?? 0);
                    $key = $mib . ':' . $days;
                    $existing = $next[$key] ?? null;

                    if (
                        $existing === null
                        || count($items) < count($existing['items'])
                        || (count($items) === count($existing['items']) && $cost < (int) $existing['cost'])
                    ) {
                        $next[$key] = [
                            'mib' => $mib,
                            'days' => $days,
                            'cost' => $cost,
                            'items' => $items,
                        ];
                    }
                }
            }

            $states = $next;
            $targetKey = $targetMib . ':' . $targetDays;
            if (isset($states[$targetKey])) {
                return $states[$targetKey]['items'];
            }
        }

        return null;
    }

    /** @param array<int,array<string,mixed>> $topups */
    private function buildRecipe(array $base, array $topups, int $targetDataBytes, int $targetDurationDays): array
    {
        $deliveredBytes = (int) $base['data_bytes'];
        $deliveredDays = (int) $base['duration_days'];
        $topupPriceRaw = 0;

        foreach ($topups as $topup) {
            $deliveredBytes += (int) ($topup['data_bytes'] ?? 0);
            $deliveredDays += (int) ($topup['duration_days'] ?? 0);
            $topupPriceRaw += (int) ($topup['provider_price_raw'] ?? 0);
        }

        if (! $this->sameData($deliveredBytes, $targetDataBytes) || $deliveredDays !== $targetDurationDays) {
            throw new RuntimeException('Internal virtual-plan resolver produced a non-exact recipe.', 500);
        }

        return [
            'strategy' => 'base_plus_included_topups_v1',
            'target_data_bytes' => $targetDataBytes,
            'target_duration_days' => $targetDurationDays,
            'base' => $base,
            'topups' => array_values($topups),
            'included_topup_count' => count($topups),
            'delivered_data_bytes' => $deliveredBytes,
            'delivered_duration_days' => $deliveredDays,
            'topup_provider_price_raw_total' => $topupPriceRaw,
        ];
    }

    private function sameData(int $left, int $right): bool
    {
        return abs($left - $right) <= self::MIB;
    }

    /**
     * Cache successful BASE -> TOPUP catalogue lookups briefly. This is primarily
     * for the full Commerce catalog audit, where many virtual sizes share the
     * same real BASE package. Fulfillment still revalidates every chosen top-up
     * against the ICCID-specific catalogue before provider spend.
     */
    private function topupCatalogForBase(string $packageCode): array
    {
        $cacheKey = 'virtual-esim:topup-catalog:v1:' . hash('sha256', $packageCode);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        // Coordinate cache misses across PHP workers as well as within one request.
        // eSIMAccess documents an 8 req/s API limit. This resolver reserves headroom
        // for the API's normal order/query traffic by limiting itself to ~4 req/s.
        return Cache::lock('virtual-esim:topup-catalog-provider-throttle', 10)->block(10, function () use ($cacheKey, $packageCode): array {
            // Another worker may have filled the cache while this one waited.
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $lastCallAt = Cache::get('virtual-esim:topup-catalog-provider-last-call');
            if (is_numeric($lastCallAt)) {
                $elapsed = microtime(true) - (float) $lastCallAt;
                $minimumInterval = 0.25;
                if ($elapsed < $minimumInterval) {
                    usleep((int) ceil(($minimumInterval - $elapsed) * 1_000_000));
                }
            }

            $response = $this->provider->listPlans([
                'type' => 'TOPUP',
                'packageCode' => $packageCode,
            ], 'primary');

            Cache::put('virtual-esim:topup-catalog-provider-last-call', microtime(true), now()->addMinutes(10));
            Cache::put($cacheKey, $response, now()->addMinutes(10));

            return $response;
        });
    }

    /** @return array<int,mixed> */
    private function extractPackageList(array $response): array
    {
        foreach ([
            'obj.packageList',
            'data.obj.packageList',
            'data.packageList',
            'packageList',
            'obj.packages',
            'data.packages',
            'packages',
            'plans',
        ] as $path) {
            $value = Arr::get($response, $path);
            if (is_array($value)) {
                return array_values($value);
            }
        }

        return [];
    }

    private function durationInDays(array $package): ?int
    {
        $duration = $this->intFromKeys($package, ['duration', 'durationDay', 'duration_days', 'validity', 'validityDays', 'days']);
        if ($duration === null || $duration <= 0) {
            return null;
        }

        $unit = strtoupper($this->stringFromKeys($package, ['durationUnit', 'duration_unit']) ?? 'DAY');

        return match ($unit) {
            'DAY', 'DAYS' => $duration,
            'HOUR', 'HOURS' => $duration % 24 === 0 ? intdiv($duration, 24) : null,
            default => null,
        };
    }

    private function stringFromKeys(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if (is_string($value) || is_numeric($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function intFromKeys(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function isRetryableProviderException(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response?->status();

            return $status === 408
                || $status === 425
                || $status === 429
                || ($status !== null && $status >= 500);
        }

        $message = strtolower($exception->getMessage());

        foreach ([
            'timeout',
            'timed out',
            'failed to connect',
            'connection refused',
            'connection reset',
            'temporarily unavailable',
            'service unavailable',
            'rate limit',
            'unable to acquire lock',
            'lock timeout',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
