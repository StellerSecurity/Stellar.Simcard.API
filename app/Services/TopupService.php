<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardActionLink;
use App\Models\SimcardTopupSession;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TopupService
{
    private const ACTION_TOPUP = 'topup';
    private const STATUS_PENDING_PAYMENT = 'PENDING_PAYMENT';
    private const STATUS_PAID = 'PAID';
    private const STATUS_FULFILLED = 'FULFILLED';
    private const STATUS_FAILED = 'FAILED';

    public function __construct(
        private readonly EsimCryptoService $crypto,
        private readonly EsimProvider $provider,
        private readonly SimcardActionLinkService $actionLinks,
    ) {}


    public function createToken(string $simId, string $reason = 'app_requested'): array
    {
        $simId = $this->normalizeSimId($simId);
        $reason = trim($reason) !== '' ? trim($reason) : 'app_requested';

        if (strlen($reason) > 64 || ! preg_match('/^[A-Za-z0-9._:\/-]+$/', $reason)) {
            throw new RuntimeException('Top-up token reason is invalid.', 422);
        }

        $simcard = $this->findSimcardForTopupSimId($simId);

        if ($simcard === null) {
            throw new RuntimeException('eSIM could not be found.', 404);
        }

        // Token creation must not require ICCID.
        // The app only needs a short top-up link here. ICCID/provider readiness is checked later
        // when the token is resolved and when the paid top-up is fulfilled.
        $topupUrl = $this->actionLinks->createTopupUrl($simcard, $reason);
        $path = parse_url($topupUrl, PHP_URL_PATH);
        $token = is_string($path) ? basename($path) : '';

        if ($token === '') {
            throw new RuntimeException('Top-up token could not be created.', 500);
        }

        return [
            'token' => $token,
            'topup_url' => $topupUrl,
            'expires_in_days' => 14,
            'sim_id' => $simId,
            'simcard_id' => (string) $simcard->id,
        ];
    }

    public function resolve(string $token): array
    {
        [$link, $simcard, $iccid] = $this->resolveValidLink($token);

        $currentPlan = $this->fallbackCurrentPlanFromSimcard($simcard);
        $plans = [];

        if ($iccid !== null) {
            $providerPlans = $this->listTopupPlansForIccid($simcard, $iccid);
            $plans = $this->normalizeTopupPlans($providerPlans);

            // The provider response is authoritative for top-up. Do not broaden the result by
            // location/current country, because normal sale package codes can fail on /esim/topup.
            $currentPlan = $this->findPlanByPackageCode($plans, (string) $simcard->package_code)
                ?: $currentPlan;
        }

        return [
            'token_status' => 'valid',
            'topup_ready' => $iccid !== null && $plans !== [],
            'link' => [
                'expires_at' => optional($link->expires_at)->toISOString(),
                'reason' => Arr::get((array) $link->metadata_redacted, 'reason'),
            ],
            'sim' => $this->safeSimPayload($simcard, $currentPlan),
            'current_plan' => $currentPlan ? $this->safePlanPayload($currentPlan) : null,
            'plans' => $plans,
            'topups' => $plans,
        ];
    }

    public function checkout(string $token, string $packageCode, array $selectedPlan = []): array
    {
        [$link, $simcard, $iccid] = $this->resolveValidLink($token);
        $packageCode = $this->normalizePackageCode($packageCode);

        if ($iccid === null) {
            throw new RuntimeException('Top-up is not ready yet for this eSIM.', 409);
        }

        $providerPlans = $this->listTopupPlansForIccid($simcard, $iccid);
        $plans = $this->normalizeTopupPlans($providerPlans);
        $matchedPlan = $this->findPlanByPackageCode($plans, $packageCode);

        if ($matchedPlan === null) {
            throw new RuntimeException('Selected package is not a valid top-up package for this eSIM.', 422);
        }

        $session = $this->createOrReuseTopupSession($link, $simcard, $matchedPlan, $packageCode);

        $commerceUrl = trim((string) config('services.stellar_commerce.payment_checkout_url', ''));
        if ($commerceUrl === '') {
            $commerceUrl = trim((string) config('services.stellar_commerce.topup_checkout_url', ''));
        }

        if ($commerceUrl === '') {
            Log::warning('Top-up checkout is not configured.', [
                'topup_session_id' => $session->id,
                'simcard_id' => $simcard->id,
                'action_link_id' => $link->id,
                'package_code' => $packageCode,
            ]);

            return [
                'status_code' => 501,
                'message' => 'Top-up payment checkout is not configured.',
                'topup_session_id' => $session->id,
                'package_code' => $packageCode,
                'plan' => $matchedPlan,
            ];
        }

        return $this->createCommerceCheckout($commerceUrl, $session, $matchedPlan);
    }

    public function fulfill(string $topupSessionId, ?string $commerceOrderId = null, ?string $commerceOrderItemId = null, ?string $idempotencyKey = null): array
    {
        $topupSessionId = $this->normalizeUuid($topupSessionId, 'Top-up session id is invalid.');

        $session = SimcardTopupSession::query()->where('id', $topupSessionId)->first();
        if ($session === null) {
            throw new RuntimeException('Top-up session could not be found.', 404);
        }

        if ($session->status === self::STATUS_FULFILLED) {
            return [
                'status' => self::STATUS_FULFILLED,
                'topup_session_id' => $session->id,
                'supplier_reference' => $session->supplier_reference,
                'idempotent' => true,
            ];
        }

        $simcard = Simcard::query()->where('id', $session->simcard_id)->first();
        if ($simcard === null) {
            throw new RuntimeException('Top-up eSIM could not be found.', 404);
        }

        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            throw new RuntimeException('Top-up is not ready yet for this eSIM.', 409);
        }

        // Do not use the Commerce idempotency key here; it can exceed the provider limit.
        $transactionId = $this->providerTransactionId($session);

        try {
            $providerResponse = $this->provider->topup($iccid, (string) $session->package_code, $transactionId);
            $redactedProviderResponse = $this->redactProviderPayload($providerResponse);

            if (! $this->providerTopupSucceeded($providerResponse)) {
                $failureReason = $this->providerFailureReason($providerResponse);

                $session->status = self::STATUS_FAILED;
                $session->commerce_order_id = $commerceOrderId ?: $session->commerce_order_id;
                $session->commerce_order_item_id = $commerceOrderItemId ?: $session->commerce_order_item_id;
                $session->supplier_reference = null;
                $session->fulfilled_at = null;
                $session->failure_reason = $failureReason;
                $session->meta = array_merge((array) $session->meta, [
                    'provider_result_redacted' => $redactedProviderResponse,
                    'provider_transaction_id' => $transactionId,
                ]);
                $session->save();

                throw new RuntimeException($failureReason, 502);
            }

            $supplierReference = $this->extractSupplierReference($providerResponse) ?: $transactionId;

            $session->status = self::STATUS_FULFILLED;
            $session->commerce_order_id = $commerceOrderId ?: $session->commerce_order_id;
            $session->commerce_order_item_id = $commerceOrderItemId ?: $session->commerce_order_item_id;
            $session->supplier_reference = $supplierReference;
            $session->paid_at = $session->paid_at ?: now();
            $session->fulfilled_at = now();
            $session->failure_reason = null;
            $session->meta = array_merge((array) $session->meta, [
                'provider_result_redacted' => $redactedProviderResponse,
                'provider_transaction_id' => $transactionId,
            ]);
            $session->save();

            return [
                'status' => self::STATUS_FULFILLED,
                'topup_session_id' => $session->id,
                'supplier_reference' => $supplierReference,
            ];
        } catch (Throwable $exception) {
            $session->status = self::STATUS_FAILED;
            $session->commerce_order_id = $commerceOrderId ?: $session->commerce_order_id;
            $session->commerce_order_item_id = $commerceOrderItemId ?: $session->commerce_order_item_id;
            $session->supplier_reference = null;
            $session->fulfilled_at = null;
            $session->failure_reason = $exception->getMessage();
            $session->meta = array_merge((array) $session->meta, [
                'provider_transaction_id' => $transactionId,
            ]);
            $session->save();

            Log::warning('Supplier top-up fulfillment failed.', [
                'topup_session_id' => $session->id,
                'simcard_id' => $simcard->id,
                'package_code' => $session->package_code,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            throw new RuntimeException('Top-up fulfillment failed: ' . $exception->getMessage(), 502);
        }
    }

    /**
     * @return array{0: SimcardActionLink, 1: Simcard, 2: string|null}
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

        // Do not hard-fail resolve/token flows when ICCID is not available yet.
        // ICCID is required only when a paid top-up is fulfilled against the provider.
        $iccid = $this->decryptIccid($simcard);

        return [$link, $simcard, $iccid];
    }

    private function listTopupPlansForIccid(Simcard $simcard, string $iccid): array
    {
        try {
            return $this->provider->listTopupPlans($iccid);
        } catch (Throwable $exception) {
            Log::warning('Could not list provider top-up plans for eSIM.', [
                'simcard_id' => $simcard->id,
                'package_code' => $simcard->package_code,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return [];
        }
    }

    private function fallbackCurrentPlanFromSimcard(Simcard $simcard): ?array
    {
        $packageCode = is_string($simcard->package_code) ? trim($simcard->package_code) : '';

        if ($packageCode === '') {
            return null;
        }

        $totalBytes = is_numeric($simcard->total_volume) && (int) $simcard->total_volume > 0
            ? (int) $simcard->total_volume
            : null;

        return array_filter([
            'package_code' => $packageCode,
            'code' => $packageCode,
            'sku' => $packageCode,
            'name' => $packageCode,
            'package_name' => $packageCode,
            'data_bytes' => $totalBytes,
            'data_gb' => $this->bytesToGb($totalBytes),
            'duration_days' => $simcard->remaining_validity,
            'validity_days' => $simcard->remaining_validity,
            'currency' => 'EUR',
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function createOrReuseTopupSession(SimcardActionLink $link, Simcard $simcard, array $plan, string $packageCode): SimcardTopupSession
    {
        $idempotencyKey = hash('sha256', implode('|', [
            'simcard-topup-session',
            $link->id,
            $simcard->id,
            $packageCode,
        ]));

        return DB::transaction(function () use ($link, $simcard, $plan, $packageCode, $idempotencyKey) {
            $existing = SimcardTopupSession::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $session = new SimcardTopupSession();
            $session->id = (string) Str::uuid();
            $session->simcard_id = $simcard->id;
            $session->action_link_id = $link->id;
            $session->package_code = $packageCode;
            $session->package_name = (string) ($plan['name'] ?? $plan['package_name'] ?? $packageCode);
            $session->data_bytes = $plan['data_bytes'] ?? null;
            $session->duration_days = $plan['duration_days'] ?? $plan['validity_days'] ?? null;
            $session->price_cents = (int) ($plan['price_cents'] ?? $plan['unit_price_cents'] ?? 0);
            $session->currency = strtoupper((string) ($plan['currency'] ?? 'EUR'));
            $session->status = self::STATUS_PENDING_PAYMENT;
            $session->idempotency_key = $idempotencyKey;
            $session->meta = [
                'plan' => $this->safePlanPayload($plan),
                'simcard_snapshot' => $this->safeSimPayload($simcard),
                'source' => 'stellar_data_topup_sms_link',
            ];
            $session->requested_at = now();
            $session->save();

            return $session;
        });
    }

    private function createCommerceCheckout(string $commerceUrl, SimcardTopupSession $session, array $plan): array
    {
        $payload = [
            'source' => 'SIMCARD_TOPUP',
            'external_reference' => (string) $session->id,
            'topup_session_id' => (string) $session->id,
            'package_code' => (string) $session->package_code,
            'description' => 'Stellar eSIM top-up',
            'currency' => (string) $session->currency,
            'amount_cents' => (int) $session->price_cents,
            'plan' => $this->safePlanPayload($plan),
            'idempotency_key' => (string) $session->idempotency_key,
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

            $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
            $orderId = (string) ($data['order_id'] ?? '');
            if ($orderId !== '') {
                $session->commerce_order_id = $orderId;
                $session->status = self::STATUS_PENDING_PAYMENT;
                $session->save();
            }

            return $body;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Top-up commerce checkout request failed.', [
                'topup_session_id' => $session->id,
                'package_code' => $session->package_code,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            throw new RuntimeException('Top-up checkout could not be created.', 502);
        }
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);

        if ($token === '' || strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9._~-]+$/', $token)) {
            throw new RuntimeException('Top-up link token is invalid.', 422);
        }

        return $token;
    }


    private function findSimcardForTopupSimId(string $simId): ?Simcard
    {
        $planIdHash = $this->crypto->derivePlanHash($simId);

        return Simcard::query()->where('plan_id_hash', $planIdHash)->first();
    }

    private function normalizeSimId(string $value): string
    {
        $value = preg_replace('/\s+/', '', trim($value));

        if (! is_string($value) || $value === '' || preg_match('/^[A-Za-z0-9]{16}$/', $value) !== 1) {
            throw new RuntimeException('Sim id is invalid.', 422);
        }

        return $value;
    }

    private function normalizePackageCode(string $packageCode): string
    {
        $packageCode = trim($packageCode);

        if ($packageCode === '' || strlen($packageCode) > 128 || ! preg_match('/^[A-Za-z0-9._:\/-]+$/', $packageCode)) {
            throw new RuntimeException('Top-up package code is invalid.', 422);
        }

        return $packageCode;
    }

    private function normalizeUuid(string $value, string $message): string
    {
        $value = trim($value);

        if ($value === '' || ! Str::isUuid($value)) {
            throw new RuntimeException($message, 422);
        }

        return $value;
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

    private function safeSimPayload(Simcard $simcard, ?array $currentPlan = null): array
    {
        $totalBytes = is_numeric($simcard->total_volume) && (int) $simcard->total_volume > 0
            ? (int) $simcard->total_volume
            : (int) ($currentPlan['data_bytes'] ?? 0);

        $payload = [
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
            'total_bytes' => $totalBytes ?: $simcard->total_volume,
            'total_data' => $this->formatBytes($totalBytes ?: $simcard->total_volume),
            'used_bytes' => $simcard->order_usage,
            'used_data' => $this->formatBytes($simcard->order_usage),
            'remaining_validity_days' => $simcard->remaining_validity,
            'expires_at' => optional($simcard->expires_at)->toISOString(),
            'activated_at' => optional($simcard->activated_at)->toISOString(),
        ];

        if ($currentPlan !== null) {
            $payload['current_plan'] = $this->safePlanPayload($currentPlan);
            $payload['current_plan_name'] = $currentPlan['name'] ?? $currentPlan['package_name'] ?? $simcard->package_code;
            $payload['current_plan_location_code'] = $this->planLocationCode($currentPlan);
            $payload['current_plan_location_name'] = $this->planLocationName($currentPlan);
        }

        return $payload;
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
            $currency = $this->stringFromKeys($package, ['currency', 'currencyCode', 'priceCurrency', 'price_currency']) ?? 'EUR';
            $locationCode = $this->stringFromKeys($package, ['locationCode', 'location_code']);
            $location = $this->stringFromKeys($package, ['location']);
            $locationName = $this->stringFromKeys($package, ['locationName', 'locationNetworkList.0.locationName']);
            $speed = $this->stringFromKeys($package, ['speed']);

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
                'location_code' => $locationCode,
                'location' => $location,
                'location_name' => $locationName,
                'speed' => $speed,
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

    private function filterPlansForCurrentPackage(array $plans, ?array $currentPlan): array
    {
        if ($currentPlan === null) {
            return [];
        }

        $currentLocationCode = $this->planLocationCode($currentPlan);
        $currentLocation = $this->normalizeLocationList($currentPlan);

        if ($currentLocationCode === null && $currentLocation === []) {
            return [];
        }

        $filtered = [];

        foreach ($plans as $plan) {
            $planLocationCode = $this->planLocationCode($plan);
            $planLocation = $this->normalizeLocationList($plan);

            $sameLocationCode = $currentLocationCode !== null
                && $planLocationCode !== null
                && strtoupper($planLocationCode) === strtoupper($currentLocationCode);

            $sameExactLocationSet = $currentLocation !== []
                && $planLocation !== []
                && $currentLocation === $planLocation;

            if ($sameLocationCode || $sameExactLocationSet) {
                $filtered[] = $plan;
            }
        }

        return array_values($filtered);
    }

    private function planLocationCode(array $plan): ?string
    {
        return $this->stringFromKeys($plan, [
            'location_code',
            'locationCode',
            'raw.locationCode',
            'raw.location_code',
            'raw.slug',
        ]);
    }

    private function planLocationName(array $plan): ?string
    {
        $name = $this->stringFromKeys($plan, [
            'location_name',
            'locationName',
            'raw.locationName',
            'raw.locationNetworkList.0.locationName',
        ]);

        if ($name !== null) {
            return $name;
        }

        $code = $this->planLocationCode($plan);

        return $code ?: null;
    }

    private function normalizeLocationList(array $plan): array
    {
        $location = $this->stringFromKeys($plan, ['location', 'raw.location']);

        if ($location === null) {
            $location = $this->planLocationCode($plan);
        }

        if ($location === null) {
            return [];
        }

        $parts = array_filter(array_map(
            static fn ($value) => strtoupper(trim((string) $value)),
            explode(',', $location)
        ));

        sort($parts);

        return array_values(array_unique($parts));
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

    private function safePlanPayload(array $plan): array
    {
        unset($plan['raw']);

        return $plan;
    }

    private function extractSupplierReference(array $payload): ?string
    {
        foreach (['obj.orderNo', 'data.orderNo', 'orderNo', 'obj.transactionId', 'data.transactionId', 'transactionId'] as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function providerTransactionId(SimcardTopupSession $session): string
    {
        $transactionId = 'tu_' . Str::replace('-', '', (string) $session->id);

        return substr($transactionId, 0, 50);
    }

    private function providerTopupSucceeded(array $payload): bool
    {
        $success = Arr::get($payload, 'success');

        if ($success === true || $success === 1 || $success === '1') {
            return true;
        }

        if (is_string($success) && strtolower($success) === 'true') {
            return true;
        }

        $errorCode = Arr::get($payload, 'errorCode') ?? Arr::get($payload, 'code');
        $errorMessage = Arr::get($payload, 'errorMsg') ?? Arr::get($payload, 'message');

        return empty($errorCode) && empty($errorMessage) && (Arr::has($payload, 'obj') || Arr::has($payload, 'data'));
    }

    private function providerFailureReason(array $payload): string
    {
        $message = Arr::get($payload, 'errorMsg')
            ?? Arr::get($payload, 'message')
            ?? Arr::get($payload, 'data.errorMsg')
            ?? Arr::get($payload, 'obj.errorMsg')
            ?? 'Provider top-up failed';

        $code = Arr::get($payload, 'errorCode')
            ?? Arr::get($payload, 'code')
            ?? Arr::get($payload, 'data.errorCode')
            ?? Arr::get($payload, 'obj.errorCode');

        if ($code !== null && trim((string) $code) !== '') {
            return trim((string) $message) . ' [' . trim((string) $code) . ']';
        }

        return trim((string) $message);
    }

    private function redactProviderPayload(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower(str_replace(['-', '_'], '', (string) $key));

            if (in_array($normalized, ['iccid', 'imsi', 'eid', 'msisdn', 'phone', 'phonenumber', 'activationcode', 'qrcode', 'matchingid', 'smdpaddress', 'token', 'secretkey', 'signature'], true)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactProviderPayload($value) : $value;
        }

        return $redacted;
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
                return max(0, (int) round(((float) $value) / 10));
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
