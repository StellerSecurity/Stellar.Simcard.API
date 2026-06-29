<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardActionLink;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TopupService
{
    private const ACTION_TOPUP = 'topup';

    public function __construct(
        private readonly EsimCryptoService $crypto,
        private readonly EsimProvider $provider,
    ) {}

    public function resolve(string $token): array
    {
        [$link, $simcard, $iccid] = $this->resolveValidLink($token);
        $providerPlans = $this->provider->listPlans(['iccid' => $iccid]);
        $plans = $this->normalizeTopupPlans($providerPlans);

        return [
            'token_status' => 'valid',
            'link' => [
                'expires_at' => optional($link->expires_at)->toISOString(),
                'reason' => Arr::get((array) $link->metadata_redacted, 'reason'),
            ],
            'sim' => $this->safeSimPayload($simcard),
            'plans' => $plans,
            'topups' => $plans,
        ];
    }

    public function checkout(string $token, string $packageCode, array $selectedPlan = []): array
    {
        [$link, $simcard, $iccid] = $this->resolveValidLink($token);
        $packageCode = $this->normalizePackageCode($packageCode);

        $providerPlans = $this->provider->listPlans(['iccid' => $iccid]);
        $plans = $this->normalizeTopupPlans($providerPlans);
        $matchedPlan = $this->findPlanByPackageCode($plans, $packageCode);

        if ($matchedPlan === null) {
            throw new RuntimeException('Selected top-up package is not available for this eSIM.', 422);
        }

        $commerceUrl = trim((string) config('services.stellar_commerce.topup_checkout_url', ''));
        if ($commerceUrl !== '') {
            return $this->createCommerceCheckout($commerceUrl, $token, $packageCode, $matchedPlan, $simcard, $link);
        }

        $checkoutBaseUrl = trim((string) config('services.stellar_data.topup_checkout_url', ''));
        if ($checkoutBaseUrl !== '') {
            return [
                'checkout_url' => $this->buildCheckoutUrl($checkoutBaseUrl, $token, $packageCode),
                'package_code' => $packageCode,
                'plan' => $matchedPlan,
            ];
        }

        Log::warning('Top-up checkout is not configured.', [
            'simcard_id' => $simcard->id,
            'action_link_id' => $link->id,
            'package_code' => $packageCode,
        ]);

        return [
            'status_code' => 501,
            'message' => 'Top-up payment checkout is not configured.',
            'package_code' => $packageCode,
            'plan' => $matchedPlan,
        ];
    }

    /**
     * @return array{0: SimcardActionLink, 1: Simcard, 2: string}
     */
    private function resolveValidLink(string $token): array
    {
        $token = $this->normalizeToken($token);
        $tokenHash = $this->crypto->deriveActionLinkTokenHash($token);

        $link = SimcardActionLink::query()
            ->where('token_hash', $tokenHash)
            ->where('action', self::ACTION_TOPUP)
            ->first();

        if ($link === null) {
            throw new RuntimeException('Top-up link is invalid.', 404);
        }

        if ($link->expires_at === null || $link->expires_at->isPast()) {
            throw new RuntimeException('Top-up link has expired.', 410);
        }

        $simcard = Simcard::query()->where('id', $link->simcard_id)->first();
        if ($simcard === null) {
            throw new RuntimeException('Top-up eSIM could not be found.', 404);
        }

        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            throw new RuntimeException('Top-up is not ready yet for this eSIM.', 409);
        }

        return [$link, $simcard, $iccid];
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);

        if ($token === '' || strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9._~-]+$/', $token)) {
            throw new RuntimeException('Top-up link token is invalid.', 422);
        }

        return $token;
    }

    private function normalizePackageCode(string $packageCode): string
    {
        $packageCode = trim($packageCode);

        if ($packageCode === '' || strlen($packageCode) > 128 || ! preg_match('/^[A-Za-z0-9._:\/-]+$/', $packageCode)) {
            throw new RuntimeException('Top-up package code is invalid.', 422);
        }

        return $packageCode;
    }

    private function decryptIccid(Simcard $simcard): ?string
    {
        $encrypted = $simcard->iccid_enc;

        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            return $this->crypto->decryptSensitiveValue($encrypted);
        } catch (Throwable $exception) {
            Log::warning('Could not decrypt ICCID for top-up link.', [
                'simcard_id' => $simcard->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return null;
        }
    }

    private function safeSimPayload(Simcard $simcard): array
    {
        return [
            'id' => $simcard->id,
            'label' => 'Stellar eSIM',
            'provider' => $simcard->provider,
            'package_code' => $simcard->package_code,
            'state' => $simcard->state,
            'esim_status' => $simcard->esim_status,
            'smdp_status' => $simcard->smdp_status,
            'data_status' => $simcard->data_status,
            'validity_status' => $simcard->validity_status,
            'iccid_last4' => $simcard->iccid_last4,
            'remaining_bytes' => $simcard->remaining_volume,
            'remaining_data' => $this->formatBytes($simcard->remaining_volume),
            'total_bytes' => $simcard->total_volume,
            'used_bytes' => $simcard->order_usage,
            'remaining_validity_days' => $simcard->remaining_validity,
            'expires_at' => optional($simcard->expires_at)->toISOString(),
            'activated_at' => optional($simcard->activated_at)->toISOString(),
        ];
    }

    private function normalizeTopupPlans(array $providerResponse): array
    {
        $packages = $this->extractPackageList($providerResponse);
        $plans = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $code = $this->stringFromKeys($package, ['packageCode', 'package_code', 'code', 'sku']);
            if ($code === null) {
                continue;
            }

            $name = $this->stringFromKeys($package, ['packageName', 'name', 'title', 'label']);
            $volumeBytes = $this->intFromKeys($package, ['volume', 'totalVolume', 'dataVolume', 'data', 'amount']);
            $durationDays = $this->intFromKeys($package, ['duration', 'durationDay', 'duration_days', 'validity', 'validityDays', 'validity_days', 'days']);
            $priceCents = $this->priceCentsFromPackage($package);
            $currency = $this->stringFromKeys($package, ['currency', 'priceCurrency', 'price_currency']) ?? 'EUR';

            $plans[] = array_filter([
                'package_code' => $code,
                'code' => $code,
                'sku' => $code,
                'name' => $name ?? $code,
                'package_name' => $name ?? $code,
                'data_gb' => $this->bytesToGb($volumeBytes),
                'data_bytes' => $volumeBytes,
                'duration_days' => $durationDays,
                'validity_days' => $durationDays,
                'price_cents' => $priceCents,
                'unit_price_cents' => $priceCents,
                'currency' => strtoupper($currency),
                'raw' => $package,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return $plans;
    }

    private function extractPackageList(array $response): array
    {
        $candidates = [
            Arr::get($response, 'obj.packageList'),
            Arr::get($response, 'obj.packageList.data'),
            Arr::get($response, 'obj.packages'),
            Arr::get($response, 'data.packageList'),
            Arr::get($response, 'data.packages'),
            Arr::get($response, 'packageList'),
            Arr::get($response, 'packages'),
            Arr::get($response, 'plans'),
            Arr::get($response, 'data'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function findPlanByPackageCode(array $plans, string $packageCode): ?array
    {
        foreach ($plans as $plan) {
            $candidate = (string) ($plan['package_code'] ?? $plan['code'] ?? $plan['sku'] ?? '');
            if ($candidate === $packageCode) {
                return $plan;
            }
        }

        return null;
    }

    private function createCommerceCheckout(
        string $commerceUrl,
        string $token,
        string $packageCode,
        array $plan,
        Simcard $simcard,
        SimcardActionLink $link,
    ): array {
        $payload = [
            'token' => $token,
            'package_code' => $packageCode,
            'plan' => $plan,
            'simcard' => $this->safeSimPayload($simcard),
            'source' => 'stellar_data_topup_sms_link',
            'idempotency_key' => hash('sha256', implode('|', [
                'topup',
                $link->id,
                $simcard->id,
                $packageCode,
            ])),
        ];

        $username = (string) config('services.stellar_commerce.username', '');
        $password = (string) config('services.stellar_commerce.password', '');

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->retry(2, 150, fn ($exception) => $exception instanceof ConnectionException);

            if ($username !== '' || $password !== '') {
                $request = $request->withBasicAuth($username, $password);
            }

            $response = $request->post($commerceUrl, $payload);
            $body = $response->json();

            if (! is_array($body)) {
                $body = [
                    'message' => $response->body() ?: 'Commerce checkout returned an invalid response.',
                ];
            }

            if ($response->failed()) {
                $message = $body['response_message'] ?? $body['message'] ?? 'Commerce checkout could not be created.';
                throw new RuntimeException((string) $message, $response->status());
            }

            return $body;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Top-up commerce checkout request failed.', [
                'simcard_id' => $simcard->id,
                'action_link_id' => $link->id,
                'package_code' => $packageCode,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            throw new RuntimeException('Top-up checkout could not be created.', 502);
        }
    }

    private function buildCheckoutUrl(string $baseUrl, string $token, string $packageCode): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . http_build_query([
            'token' => $token,
            'package_code' => $packageCode,
        ]);
    }

    private function stringFromKeys(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function intFromKeys(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }

    private function priceCentsFromPackage(array $package): ?int
    {
        $cents = $this->intFromKeys($package, ['priceCents', 'price_cents', 'unit_price_cents', 'amount_cents']);
        if ($cents !== null) {
            return $cents;
        }

        foreach (['price', 'unitPrice', 'unit_price', 'amount'] as $key) {
            $value = Arr::get($package, $key);
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return max(0, (int) round(((float) $value) * 100));
            }
        }

        return null;
    }

    private function bytesToGb(?int $bytes): ?float
    {
        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        return round($bytes / 1024 / 1024 / 1024, 2);
    }

    private function formatBytes(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024 / 1024, 2, '.', ''), '0'), '.') . ' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024, 2, '.', ''), '0'), '.') . ' MB';
        }

        return $bytes . ' bytes';
    }
}
