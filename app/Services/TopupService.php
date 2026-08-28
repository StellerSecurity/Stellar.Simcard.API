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
        private readonly VirtualEsimQuotaService $virtualQuotaService,
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

        $this->assertTopupEligible($simcard);

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
        $this->assertTopupEligible($simcard);

        $providerPlans = $this->listPlansForResolve($simcard, $iccid);
        $currentPlan = $this->currentPlanForSimcard($simcard);

        $normalizedPlans = $this->normalizeTopupPlans($providerPlans);

        Log::info('Top-up resolve plan pipeline.', [
            'simcard_id' => (string) $simcard->id,
            'provider' => (string) $simcard->provider,
            'provider_account' => (string) $simcard->provider_account,
            'package_code' => (string) $simcard->package_code,
            'has_iccid' => $iccid !== null && trim($iccid) !== '',
            'provider_response_keys' => array_keys($providerPlans),
            'provider_response_shape' => $this->describeArrayShape($providerPlans),
            'extracted_package_count' => count($this->extractPackageList($providerPlans)),
            'normalized_plan_count' => count($normalizedPlans),
            'current_plan_found' => $currentPlan !== null,
            'current_plan_location_code' => $currentPlan !== null ? $this->planLocationCode($currentPlan) : null,
            'current_plan_location' => $currentPlan !== null ? $this->normalizeLocationList($currentPlan) : [],
        ]);

        $plans = array_values(array_map(
            fn (array $plan): array => $this->customerTopupPlan($plan),
            $this->fixedTopupPlans($normalizedPlans),
        ));

        Log::info('Top-up resolve fixed plans prepared.', [
            'simcard_id' => (string) $simcard->id,
            'normalized_plan_count' => count($normalizedPlans),
            'fixed_plan_count' => count($plans),
            'fixed_package_codes' => array_values(array_filter(array_map(
                static fn (array $plan): ?string => isset($plan['package_code']) ? (string) $plan['package_code'] : null,
                $plans
            ))),
        ]);

        return [
            'token_status' => 'valid',
            'topup_ready' => $iccid !== null,
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
        $this->assertTopupEligible($simcard);
        $packageCode = $this->normalizePackageCode($packageCode);

        if ($iccid === null || trim($iccid) === '') {
            throw new RuntimeException('Top-up is not ready yet for this eSIM.', 409);
        }

        $account = $this->resolveProviderAccount($simcard, $iccid);
        $this->assertProviderTopupEligible($iccid, $account);
        $providerPlans = $this->provider->listPlans([
            'type' => 'TOPUP',
            'iccid' => $iccid,
        ], $account);
        $plans = $this->fixedTopupPlans($this->normalizeTopupPlans($providerPlans));
        $matchedPlan = $this->findPlanByPackageCode($plans, $packageCode);

        if ($matchedPlan === null) {
            throw new RuntimeException('Selected top-up package is not available for this eSIM.', 422);
        }

        $matchedPlan = $this->applyTrustedCustomerPricing($matchedPlan, $selectedPlan);
        $matchedPlan = $this->customerTopupPlan($matchedPlan);

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

    /**
     * Prepare a provider-validated top-up session for a payment that has
     * already been completed by an internal caller, for example a wholesale
     * wallet debit. No Commerce checkout is created here.
     *
     * @return array<string,mixed>
     */
    public function preparePaidSession(
        string $token,
        string $packageCode,
        string $idempotencyKey,
        ?string $externalReference = null,
        ?string $paymentReference = null,
        string $source = 'internal_paid_topup',
    ): array {
        [$link, $simcard, $iccid] = $this->resolveValidLink($token);
        $this->assertTopupEligible($simcard);
        $packageCode = $this->normalizePackageCode($packageCode);
        $idempotencyKey = trim($idempotencyKey);
        $externalReference = $this->nullableTrimmedString($externalReference, 128);
        $paymentReference = $this->nullableTrimmedString($paymentReference, 191);
        $source = trim($source) !== '' ? trim($source) : 'internal_paid_topup';

        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
            throw new RuntimeException('Top-up idempotency key is invalid.', 422);
        }

        if (strlen($source) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $source) !== 1) {
            throw new RuntimeException('Top-up payment source is invalid.', 422);
        }

        if ($iccid === null || trim($iccid) === '') {
            throw new RuntimeException('Top-up is not ready yet for this eSIM.', 409);
        }

        $account = $this->resolveProviderAccount($simcard, $iccid);
        $this->assertProviderTopupEligible($iccid, $account);
        $providerPlans = $this->provider->listPlans([
            'type' => 'TOPUP',
            'iccid' => $iccid,
        ], $account);
        $plans = $this->fixedTopupPlans($this->normalizeTopupPlans($providerPlans));
        $matchedPlan = $this->findPlanByPackageCode($plans, $packageCode);

        if ($matchedPlan === null) {
            throw new RuntimeException('Selected top-up package is not available for this eSIM.', 422);
        }

        [$session, $created] = $this->createOrReusePaidTopupSession(
            link: $link,
            simcard: $simcard,
            plan: $matchedPlan,
            packageCode: $packageCode,
            callerIdempotencyKey: $idempotencyKey,
            externalReference: $externalReference,
            paymentReference: $paymentReference,
            source: $source,
        );

        return [
            'status' => $session->status,
            'topup_session_id' => (string) $session->id,
            'package_code' => (string) $session->package_code,
            'supplier_reference' => $session->supplier_reference,
            'idempotent' => ! $created,
        ];
    }

    /**
     * Prepare an idempotent provider-validated session for Auto Top-Up.
     * No payment is made here. Commerce remains the only owner of card charges.
     */
    public function prepareAutoTopupSession(
        Simcard $simcard,
        int $preferredDataBytes,
        ?int $preferredDurationDays,
        string $attemptKey,
    ): SimcardTopupSession {
        $this->assertAutoTopupEligible($simcard);

        if ($preferredDataBytes <= 0) {
            throw new RuntimeException('Auto Top-Up data allowance is invalid.', 422);
        }

        $attemptKey = trim($attemptKey);
        if (strlen($attemptKey) < 16 || strlen($attemptKey) > 128) {
            throw new RuntimeException('Auto Top-Up attempt key is invalid.', 422);
        }

        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null || trim($iccid) === '') {
            throw new RuntimeException('Auto Top-Up is not ready for this eSIM yet.', 409);
        }

        $account = $this->resolveProviderAccount($simcard, $iccid);
        $this->assertProviderTopupEligible($iccid, $account);
        $providerPlans = $this->provider->listPlans([
            'type' => 'TOPUP',
            'iccid' => $iccid,
        ], $account);
        $plans = $this->fixedTopupPlans($this->normalizeTopupPlans($providerPlans));

        $toleranceBytes = max(1024 * 1024, (int) round($preferredDataBytes * 0.005));
        $matches = array_values(array_filter($plans, static function (array $plan) use ($preferredDataBytes, $toleranceBytes): bool {
            $dataBytes = $plan['data_bytes'] ?? null;

            return is_numeric($dataBytes)
                && abs((int) $dataBytes - $preferredDataBytes) <= $toleranceBytes;
        }));

        if ($preferredDurationDays !== null && $preferredDurationDays > 0 && count($matches) > 1) {
            usort($matches, static function (array $left, array $right) use ($preferredDurationDays): int {
                $leftDays = (int) ($left['duration_days'] ?? $left['validity_days'] ?? 0);
                $rightDays = (int) ($right['duration_days'] ?? $right['validity_days'] ?? 0);

                return abs($leftDays - $preferredDurationDays) <=> abs($rightDays - $preferredDurationDays);
            });
        }

        $matchedPlan = $matches[0] ?? null;
        if ($matchedPlan === null) {
            throw new RuntimeException('No compatible top-up with the same data allowance is available for this eSIM.', 422);
        }

        $packageCode = $this->normalizePackageCode((string) ($matchedPlan['package_code'] ?? ''));
        $sessionIdempotencyKey = hash('sha256', 'simcard-auto-topup-session|' . $attemptKey);

        return DB::transaction(function () use (
            $simcard,
            $matchedPlan,
            $packageCode,
            $attemptKey,
            $sessionIdempotencyKey,
        ): SimcardTopupSession {
            $existing = SimcardTopupSession::query()
                ->where('idempotency_key', $sessionIdempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ((string) $existing->simcard_id !== (string) $simcard->id) {
                    throw new RuntimeException('Auto Top-Up attempt key was already used for another eSIM.', 409);
                }

                return $existing;
            }

            $session = new SimcardTopupSession();
            $session->id = (string) Str::uuid();
            $session->simcard_id = (string) $simcard->id;
            $session->action_link_id = null;
            $session->package_code = $packageCode;
            $session->package_name = (string) ($matchedPlan['name'] ?? $matchedPlan['package_name'] ?? $packageCode);
            $session->data_bytes = (int) ($matchedPlan['data_bytes'] ?? 0);
            $session->duration_days = isset($matchedPlan['duration_days']) ? (int) $matchedPlan['duration_days'] : null;
            $session->price_cents = (int) ($matchedPlan['price_cents'] ?? $matchedPlan['unit_price_cents'] ?? 0);
            $session->currency = strtoupper((string) ($matchedPlan['currency'] ?? 'EUR'));
            $session->status = self::STATUS_PENDING_PAYMENT;
            $session->idempotency_key = $sessionIdempotencyKey;
            $session->meta = [
                'source' => 'esim_auto_topup',
                'attempt_key_hash' => hash('sha256', $attemptKey),
                'plan' => $this->safePlanPayload($matchedPlan),
                'simcard_snapshot' => $this->safeSimPayload($simcard),
            ];
            $session->requested_at = now();
            $session->save();

            return $session;
        });
    }

    /**
     * Prepare an internally funded top-up used only to compose a virtual Stellar plan.
     *
     * No customer payment, Commerce checkout, Stripe call or Auto Top-Up state is
     * involved. The requested package is revalidated against the ICCID-specific
     * provider TOPUP catalogue before an auditable PAID session is created.
     */
    public function prepareIncludedVirtualTopupSession(
        Simcard $simcard,
        string $packageCode,
        string $idempotencyKey,
        ?string $commerceOrderId = null,
        ?string $commerceOrderItemId = null,
        ?int $commerceUnit = null,
        ?int $step = null,
    ): SimcardTopupSession {
        $packageCode = $this->normalizePackageCode($packageCode);
        $idempotencyKey = trim($idempotencyKey);

        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
            throw new RuntimeException('Virtual-plan top-up idempotency key is invalid.', 422);
        }

        $existing = SimcardTopupSession::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ((string) $existing->simcard_id !== (string) $simcard->id) {
                throw new RuntimeException('Virtual-plan top-up idempotency key belongs to another eSIM.', 409);
            }

            return $existing;
        }

        $this->assertIncludedVirtualTopupEligible($simcard);
        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null || trim($iccid) === '') {
            throw new RuntimeException('Virtual-plan top-up is not ready yet for this eSIM.', 503);
        }

        $account = $this->resolveProviderAccount($simcard, $iccid);
        $this->assertProviderTopupEligible($iccid, $account, true);
        $providerPlans = $this->provider->listPlans([
            'type' => 'TOPUP',
            'iccid' => $iccid,
        ], $account);
        $plans = $this->fixedTopupPlans($this->normalizeTopupPlans($providerPlans));
        $matchedPlan = $this->findPlanByPackageCode($plans, $packageCode);

        if ($matchedPlan === null) {
            throw new RuntimeException('Virtual-plan top-up package is not available for this eSIM yet.', 503);
        }

        $canonicalPackageCode = (string) (
            $matchedPlan['package_code']
            ?? $matchedPlan['provider_topup_slug']
            ?? $matchedPlan['provider_topup_value']
            ?? $packageCode
        );

        return DB::transaction(function () use (
            $simcard,
            $matchedPlan,
            $canonicalPackageCode,
            $packageCode,
            $idempotencyKey,
            $commerceOrderId,
            $commerceOrderItemId,
            $commerceUnit,
            $step,
        ): SimcardTopupSession {
            $existing = SimcardTopupSession::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ((string) $existing->simcard_id !== (string) $simcard->id) {
                    throw new RuntimeException('Virtual-plan top-up idempotency key belongs to another eSIM.', 409);
                }

                return $existing;
            }

            $session = new SimcardTopupSession();
            $session->id = (string) Str::uuid();
            $session->simcard_id = (string) $simcard->id;
            $session->action_link_id = null;
            $session->package_code = $canonicalPackageCode;
            $session->package_name = (string) ($matchedPlan['name'] ?? $matchedPlan['package_name'] ?? $canonicalPackageCode);
            $session->data_bytes = (int) ($matchedPlan['data_bytes'] ?? 0);
            $session->duration_days = isset($matchedPlan['duration_days']) ? (int) $matchedPlan['duration_days'] : null;
            $session->price_cents = (int) ($matchedPlan['price_cents'] ?? $matchedPlan['unit_price_cents'] ?? 0);
            $session->currency = strtoupper((string) ($matchedPlan['currency'] ?? 'USD'));
            $session->status = self::STATUS_PAID;
            $session->idempotency_key = $idempotencyKey;
            $session->commerce_order_id = $commerceOrderId ?: null;
            $session->commerce_order_item_id = $commerceOrderItemId ?: null;
            $session->meta = array_filter([
                'source' => 'virtual_plan_fulfillment',
                'customer_charge' => false,
                'requested_package_code' => $packageCode,
                'commerce_unit' => $commerceUnit,
                'virtual_topup_step' => $step,
                'plan' => $this->safePlanPayload($matchedPlan),
                'simcard_snapshot' => $this->safeSimPayload($simcard),
            ], static fn ($value) => $value !== null && $value !== '');
            $session->requested_at = now();
            $session->paid_at = now();
            $session->save();

            return $session;
        });
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

        $restoredQuotaProfileForPaidTopup = false;

        if ($this->isIncludedVirtualTopupSession($session)) {
            $this->assertIncludedVirtualTopupEligible($simcard);
        } else {
            if ($this->virtualQuotaService->allowsPaidTopupWhileSuspended($simcard)) {
                $this->virtualQuotaService->restoreForPaidTopup($simcard);
                $restoredQuotaProfileForPaidTopup = true;
                $simcard->refresh();
            }

            $this->assertTopupEligible($simcard);
        }

        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            throw new RuntimeException(
                'Top-up is not ready yet for this eSIM.',
                $this->isIncludedVirtualTopupSession($session) ? 503 : 409,
            );
        }

        // Do not use the Commerce idempotency key here; it can exceed the provider limit.
        $transactionId = $this->providerTransactionId($session);

        try {
            // Resolve ownership with a read-only request, then revalidate the selected
            // package against the provider's ICCID-specific TOPUP catalogue.
            $account = $this->resolveProviderAccount($simcard, $iccid);
            $this->assertProviderTopupEligible(
                $iccid,
                $account,
                $this->isIncludedVirtualTopupSession($session),
            );
            [$providerPlan, $providerTopupValue] = $this->resolveProviderPlanForFulfillment(
                $session,
                $iccid,
                $account,
            );

            $providerResponse = $this->provider->topup($iccid, $providerTopupValue, $transactionId, $account);
            $redactedProviderResponse = $this->redactProviderPayload($providerResponse);

            if (! $this->providerTopupSucceeded($providerResponse)) {
                $failureReason = $this->providerFailureReason($providerResponse);

                if ($this->providerTopupFailureIsRetryable($providerResponse)) {
                    // Payment has already completed at this point. Keep the session retryable
                    // instead of permanently failing because the supplier wallet is low.
                    $session->status = self::STATUS_PAID;
                    $session->commerce_order_id = $commerceOrderId ?: $session->commerce_order_id;
                    $session->commerce_order_item_id = $commerceOrderItemId ?: $session->commerce_order_item_id;
                    $session->paid_at = $session->paid_at ?: now();
                    $session->supplier_reference = null;
                    $session->fulfilled_at = null;
                    $session->failure_reason = 'Retryable top-up fulfillment error: ' . $failureReason;
                    $session->meta = array_merge((array) $session->meta, [
                        'provider_result_redacted' => $redactedProviderResponse,
                        'provider_transaction_id' => $transactionId,
                        'provider_topup_value' => $providerTopupValue,
                        'provider_fulfillment_plan' => $this->safePlanPayload($providerPlan),
                        'last_retryable_error' => $failureReason,
                        'last_retryable_error_at' => now()->toIso8601String(),
                    ]);
                    $session->save();

                    throw new RuntimeException($failureReason, 503);
                }

                $session->status = self::STATUS_FAILED;
                $session->commerce_order_id = $commerceOrderId ?: $session->commerce_order_id;
                $session->commerce_order_item_id = $commerceOrderItemId ?: $session->commerce_order_item_id;
                $session->supplier_reference = null;
                $session->fulfilled_at = null;
                $session->failure_reason = $failureReason;
                $session->meta = array_merge((array) $session->meta, [
                    'provider_result_redacted' => $redactedProviderResponse,
                    'provider_transaction_id' => $transactionId,
                    'provider_topup_value' => $providerTopupValue,
                    'provider_fulfillment_plan' => $this->safePlanPayload($providerPlan),
                ]);
                $session->save();

                throw new RuntimeException($failureReason, 422);
            }

            $supplierReference = $this->extractSupplierReference($providerResponse) ?: $transactionId;
            $providerTopupReference = $this->extractProviderTopupReference($providerResponse);
            $providerOrderReference = $this->extractProviderOrderReference($providerResponse);

            $session->status = self::STATUS_FULFILLED;
            $session->commerce_order_id = $commerceOrderId ?: $session->commerce_order_id;
            $session->commerce_order_item_id = $commerceOrderItemId ?: $session->commerce_order_item_id;
            $session->supplier_reference = $supplierReference;
            $session->paid_at = $session->paid_at ?: now();
            $session->fulfilled_at = now();
            $session->failure_reason = null;
            $session->meta = array_merge((array) $session->meta, array_filter([
                'provider_result_redacted' => $redactedProviderResponse,
                'provider_transaction_id' => $transactionId,
                'provider_topup_value' => $providerTopupValue,
                'provider_fulfillment_plan' => $this->safePlanPayload($providerPlan),
                'provider_topup_esim_tran_no' => $providerTopupReference,
                'provider_order_no' => $providerOrderReference,
                'support_reference' => $supplierReference,
            ], static fn ($value) => $value !== null && $value !== ''));
            $session->save();

            if (! $this->isIncludedVirtualTopupSession($session)) {
                $this->virtualQuotaService->extendEntitlementForPaidTopup($simcard->fresh() ?? $simcard, $session);
            }

            return [
                'status' => self::STATUS_FULFILLED,
                'topup_session_id' => $session->id,
                'supplier_reference' => $supplierReference,
            ];
        } catch (Throwable $exception) {
            // If a quota-capped profile had to be unsuspended so a customer-paid
            // provider top-up could be attempted, never leave the old exhausted
            // entitlement open after a failed/ambiguous top-up write. Re-evaluate
            // the stored usage immediately; this queues the dedicated quota suspend
            // job again when the previous entitlement is still exhausted. A later
            // idempotent top-up retry can extend entitlement and restore the profile.
            if ($restoredQuotaProfileForPaidTopup) {
                try {
                    $this->virtualQuotaService->processStoredUsage((string) $simcard->id);
                } catch (Throwable $quotaException) {
                    Log::warning('Quota-capped eSIM could not be re-queued for suspension after paid top-up failure.', [
                        'simcard_id' => (string) $simcard->id,
                        'topup_session_id' => (string) $session->id,
                        'exception' => basename(str_replace('\\', '/', get_class($quotaException))),
                    ]);
                }
            }

            $session->commerce_order_id = $commerceOrderId ?: $session->commerce_order_id;
            $session->commerce_order_item_id = $commerceOrderItemId ?: $session->commerce_order_item_id;

            $providerHttpStatus = $this->providerExceptionHttpStatus($exception);
            $runtimeStatus = $exception instanceof RuntimeException ? (int) $exception->getCode() : 0;

            if (
                $this->isRetryableProviderException($exception)
                || $runtimeStatus >= 500
                || $providerHttpStatus === 429
                || $providerHttpStatus >= 500
            ) {
                // Do not permanently fail paid top-ups on provider/network timeouts.
                // Commerce can retry with the same provider transaction id.
                $session->failure_reason = 'Retryable top-up fulfillment error: ' . $exception->getMessage();
                $session->meta = array_merge((array) $session->meta, [
                    'provider_transaction_id' => $transactionId,
                    'last_retryable_error' => $exception->getMessage(),
                    'last_retryable_error_at' => now()->toIso8601String(),
                ]);
                $session->save();

                Log::warning('Supplier top-up fulfillment retryable failure.', [
                    'topup_session_id' => $session->id,
                    'simcard_id' => $simcard->id,
                    'package_code' => $session->package_code,
                    'exception' => basename(str_replace('\\', '/', get_class($exception))),
                ]);

                throw new RuntimeException('Top-up fulfillment temporarily unavailable: ' . $exception->getMessage(), 503);
            }

            $session->status = self::STATUS_FAILED;
            $session->supplier_reference = null;
            $session->fulfilled_at = null;
            $session->failure_reason = $exception->getMessage();
            $session->meta = array_merge((array) $session->meta, array_filter([
                'provider_transaction_id' => $transactionId,
                'provider_http_status' => $providerHttpStatus > 0 ? $providerHttpStatus : null,
            ], static fn ($value) => $value !== null));
            $session->save();

            Log::warning('Supplier top-up fulfillment failed.', [
                'topup_session_id' => $session->id,
                'simcard_id' => $simcard->id,
                'package_code' => $session->package_code,
                'provider_http_status' => $providerHttpStatus > 0 ? $providerHttpStatus : null,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            if ($runtimeStatus >= 400 && $runtimeStatus <= 499) {
                throw $exception;
            }

            if ($providerHttpStatus >= 400 && $providerHttpStatus <= 499) {
                throw new RuntimeException('The provider rejected the top-up.', 422, $exception);
            }

            throw new RuntimeException('Top-up fulfillment failed: ' . $exception->getMessage(), 502, $exception);
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

    private function listPlansForResolve(Simcard $simcard, ?string $iccid): array
    {
        if ($iccid === null || trim($iccid) === '') {
            Log::warning('Top-up provider plan lookup skipped because ICCID is unavailable.', [
                'simcard_id' => (string) $simcard->id,
                'provider' => (string) $simcard->provider,
                'provider_account' => (string) $simcard->provider_account,
                'package_code' => (string) $simcard->package_code,
                'has_iccid' => false,
            ]);

            return [];
        }

        try {
            $account = $this->resolveProviderAccount($simcard, $iccid);
            $this->assertProviderTopupEligible($iccid, $account);

            Log::info('Requesting top-up plans from provider.', [
                'simcard_id' => (string) $simcard->id,
                'provider' => (string) $simcard->provider,
                'resolved_provider_account' => $account,
                'package_code' => (string) $simcard->package_code,
                'has_iccid' => true,
                'iccid_last4' => strlen($iccid) >= 4 ? substr($iccid, -4) : null,
                'filter_keys' => ['type', 'iccid'],
            ]);

            $response = $this->provider->listPlans([
                'type' => 'TOPUP',
                'iccid' => $iccid,
            ], $account);

            Log::info('Provider top-up plan response received.', [
                'simcard_id' => (string) $simcard->id,
                'resolved_provider_account' => $account,
                'response_keys' => array_keys($response),
                'response_shape' => $this->describeArrayShape($response),
                'success' => Arr::get($response, 'success'),
                'error_code' => Arr::get($response, 'errorCode') ?? Arr::get($response, 'code'),
                'error_message' => Arr::get($response, 'errorMsg') ?? Arr::get($response, 'message'),
                'obj_package_list_type' => get_debug_type(Arr::get($response, 'obj.packageList')),
                'obj_package_list_count' => is_array(Arr::get($response, 'obj.packageList'))
                    ? count(Arr::get($response, 'obj.packageList'))
                    : null,
                'data_obj_package_list_type' => get_debug_type(Arr::get($response, 'data.obj.packageList')),
                'data_obj_package_list_count' => is_array(Arr::get($response, 'data.obj.packageList'))
                    ? count(Arr::get($response, 'data.obj.packageList'))
                    : null,
            ]);

            return $response;
        } catch (Throwable $exception) {
            Log::warning('Could not list top-up plans while resolving top-up link.', [
                'simcard_id' => (string) $simcard->id,
                'has_iccid' => true,
                'iccid_last4' => strlen($iccid) >= 4 ? substr($iccid, -4) : null,
                'package_code' => (string) $simcard->package_code,
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
                'exception_code' => $exception->getCode(),
            ]);

            return [];
        }
    }

    private function currentPlanForSimcard(Simcard $simcard, ?string $account = null): ?array
    {
        $packageCode = is_string($simcard->package_code) ? trim($simcard->package_code) : '';

        if ($packageCode !== '') {
            try {
                $response = $this->provider->listPlans([
                    'type' => 'BASE',
                    'packageCode' => $packageCode,
                ], $account ?? $this->preferredProviderAccount($simcard));
                $packages = $this->extractPackageList($response);

                foreach ($packages as $package) {
                    if (! is_array($package)) {
                        continue;
                    }

                    $candidate = $this->stringFromKeys($package, ['packageCode', 'package_code', 'code', 'sku']);

                    if ($candidate !== null && strcasecmp($candidate, $packageCode) === 0) {
                        return $this->normalizeProviderPlan($package, $packageCode);
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Could not fetch current provider package while resolving top-up.', [
                    'simcard_id' => $simcard->id,
                    'package_code' => $packageCode,
                    'exception' => basename(str_replace('\\', '/', get_class($exception))),
                ]);
            }
        }

        return $this->fallbackCurrentPlanFromSimcard($simcard);
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
            strtoupper((string) ($plan['currency'] ?? '')),
            (int) ($plan['price_cents'] ?? $plan['unit_price_cents'] ?? 0),
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

    /**
     * @return array{0:SimcardTopupSession,1:bool}
     */
    private function createOrReusePaidTopupSession(
        SimcardActionLink $link,
        Simcard $simcard,
        array $plan,
        string $packageCode,
        string $callerIdempotencyKey,
        ?string $externalReference,
        ?string $paymentReference,
        string $source,
    ): array {
        $idempotencyKey = hash('sha256', implode('|', [
            'simcard-paid-topup-session',
            $source,
            $callerIdempotencyKey,
        ]));

        return DB::transaction(function () use (
            $link,
            $simcard,
            $plan,
            $packageCode,
            $idempotencyKey,
            $callerIdempotencyKey,
            $externalReference,
            $paymentReference,
            $source,
        ): array {
            $existing = SimcardTopupSession::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (
                    (string) $existing->simcard_id !== (string) $simcard->id
                    || ! hash_equals(
                        $this->normalizePackageCode((string) $existing->package_code),
                        $this->normalizePackageCode($packageCode),
                    )
                ) {
                    throw new RuntimeException('Top-up idempotency key was already used for another request.', 409);
                }

                return [$existing, false];
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
            $session->status = self::STATUS_PAID;
            $session->idempotency_key = $idempotencyKey;
            $session->meta = array_filter([
                'plan' => $this->safePlanPayload($plan),
                'simcard_snapshot' => $this->safeSimPayload($simcard),
                'source' => $source,
                'external_reference' => $externalReference,
                'payment_reference' => $paymentReference,
                'caller_idempotency_sha256' => hash('sha256', $callerIdempotencyKey),
            ], static fn ($value) => $value !== null && $value !== '');
            $session->requested_at = now();
            $session->paid_at = now();
            $session->save();

            return [$session, true];
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

    private function nullableTrimmedString(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return Str::limit($value, $maxLength, '');
    }

    private function providerExceptionHttpStatus(Throwable $exception): int
    {
        if ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response !== null) {
            return $exception->response->status();
        }

        return 0;
    }

    private function isRetryableProviderException(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach ([
                     'curl error 28',
                     'connection timeout',
                     'operation timed out',
                     'timeout was reached',
                     'failed to connect',
                     'could not resolve host',
                     'connection refused',
                     'connection reset',
                     'temporary failure',
                     'temporarily unavailable',
                     'service unavailable',
                     'http 503',
                 ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return $exception instanceof ConnectionException;
    }

    private function assertProviderTopupEligible(string $iccid, string $account, bool $retryWhenUnavailable = false): void
    {
        $response = $this->provider->queryEsim(null, $iccid, $account);
        $esim = Arr::get($response, 'obj.esimList.0');

        if (! is_array($esim)) {
            throw new RuntimeException(
                'The eSIM could not be verified for top-up.',
                $retryWhenUnavailable ? 503 : 409,
            );
        }

        $status = strtoupper(trim((string) ($esim['esimStatus'] ?? '')));
        if ($status !== '' && str_contains($status, 'EXPIRED')) {
            throw new RuntimeException('This eSIM has expired and can no longer be topped up.', 409);
        }

        $expiredTime = trim((string) ($esim['expiredTime'] ?? ''));
        if ($expiredTime !== '') {
            $expiresAt = strtotime($expiredTime);

            if ($expiresAt !== false && $expiresAt <= time()) {
                throw new RuntimeException('This eSIM has expired and can no longer be topped up.', 409);
            }
        }
    }

    /**
     * Resolve the provider-authoritative top-up value for a stored session.
     *
     * Public Stellar package codes remain slugs for backward compatibility, while
     * fulfillment uses the compatible TOPUP_* code returned for this exact ICCID.
     *
     * @return array{0: array<string,mixed>, 1: string}
     */
    private function resolveProviderPlanForFulfillment(
        SimcardTopupSession $session,
        string $iccid,
        string $account,
    ): array {
        $providerResponse = $this->provider->listPlans([
            'type' => 'TOPUP',
            'iccid' => $iccid,
        ], $account);

        // eSIMAccess defines TOPUP + ICCID as the authoritative compatibility query.
        // Do not re-filter those provider-approved recharge packages using BASE package
        // metadata such as supportTopUpType or location; that can reject valid TOPUP_* rows.
        $plans = $this->fixedTopupPlans($this->normalizeTopupPlans($providerResponse));

        $requestedCodes = array_values(array_unique(array_filter([
            $this->nullableTrimmedString((string) $session->package_code, 128),
            $this->nullableTrimmedString((string) Arr::get((array) $session->meta, 'plan.package_code', ''), 128),
            $this->nullableTrimmedString((string) Arr::get((array) $session->meta, 'plan.provider_topup_slug', ''), 128),
            $this->nullableTrimmedString((string) Arr::get((array) $session->meta, 'plan.provider_topup_value', ''), 128),
        ])));

        $matchedPlan = null;
        foreach ($requestedCodes as $requestedCode) {
            $matchedPlan = $this->findPlanByPackageCode($plans, $requestedCode);
            if ($matchedPlan !== null) {
                break;
            }
        }

        if ($matchedPlan === null) {
            throw new RuntimeException(
                'Selected top-up package is no longer available for this eSIM.',
                $this->isIncludedVirtualTopupSession($session) ? 503 : 409,
            );
        }

        $providerTopupValue = $this->stringFromKeys($matchedPlan, [
            'provider_topup_value',
            'provider_topup_code',
            'provider_topup_slug',
            'package_code',
        ]);

        if ($providerTopupValue === null) {
            throw new RuntimeException(
                'The provider top-up package could not be resolved.',
                $this->isIncludedVirtualTopupSession($session) ? 503 : 409,
            );
        }

        return [$matchedPlan, $providerTopupValue];
    }

    private function providerTopupFailureIsRetryable(array $payload): bool
    {
        $errorCode = (string) (
            Arr::get($payload, 'errorCode')
            ?? Arr::get($payload, 'code')
            ?? Arr::get($payload, 'data.errorCode')
            ?? Arr::get($payload, 'obj.errorCode')
            ?? ''
        );

        // eSIMAccess 200007 means the merchant/provider balance is insufficient.
        // The customer payment must remain retryable after supplier balance recovery.
        return $errorCode === '200007';
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

    private function assertAutoTopupEligible(Simcard $simcard): void
    {
        if (! in_array(strtoupper(trim((string) $simcard->esim_status)), ['IN_USE', 'USED_UP'], true)) {
            throw new RuntimeException('Auto Top-Up only runs while the eSIM is active or used up.', 409);
        }
    }

    private function isIncludedVirtualTopupSession(SimcardTopupSession $session): bool
    {
        $meta = is_array($session->meta) ? $session->meta : [];

        return (string) ($meta['source'] ?? '') === 'virtual_plan_fulfillment'
            && filter_var($meta['customer_charge'] ?? false, FILTER_VALIDATE_BOOLEAN) === false;
    }

    /**
     * Included virtual-plan top-ups are allowed before installation. eSIMAccess
     * explicitly supports top-up while a profile is New; the live provider query
     * and ICCID-specific TOPUP catalogue remain the final authority.
     */
    private function assertIncludedVirtualTopupEligible(Simcard $simcard): void
    {
        $status = strtoupper(trim((string) $simcard->esim_status));

        foreach (['EXPIRED', 'CANCEL', 'CANCELED', 'CANCELLED', 'REVOKED'] as $terminal) {
            if ($status !== '' && str_contains($status, $terminal)) {
                throw new RuntimeException('This eSIM is no longer eligible for the included virtual-plan top-up.', 409);
            }
        }
    }

    private function assertTopupEligible(Simcard $simcard): void
    {
        $providerStatus = strtoupper(trim((string) $simcard->esim_status));
        $fallbackState = strtolower(trim((string) $simcard->state));

        // eSIMAccess permits top-up before first use (GOT_RESOURCE / New),
        // while the profile is active (IN_USE), and after its data allowance
        // has been consumed (USED_UP). When the provider has supplied an eSIM
        // status, it is authoritative. Older records without that field may
        // fall back to the normalized local state.
        $eligible = $providerStatus !== ''
            ? in_array($providerStatus, ['GOT_RESOURCE', 'IN_USE', 'USED_UP'], true)
            : in_array($fallbackState, ['ok', 'active'], true);

        if (! $eligible && $this->virtualQuotaService->allowsPaidTopupWhileSuspended($simcard)) {
            $eligible = true;
        }

        if (! $eligible) {
            throw new RuntimeException(
                'Only New or active, unexpired eSIMs can be topped up.',
                409,
            );
        }
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

        $effectiveUsage = $this->virtualQuotaService->effectiveUsage(
            $simcard,
            $totalBytes ?: (is_numeric($simcard->total_volume) ? (int) $simcard->total_volume : null),
            is_numeric($simcard->order_usage) ? (int) $simcard->order_usage : null,
            is_numeric($simcard->remaining_volume) ? (int) $simcard->remaining_volume : null,
        );

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
            'remaining_bytes' => $effectiveUsage['remaining_bytes'],
            'remaining_data' => $this->formatBytes($effectiveUsage['remaining_bytes']),
            'total_bytes' => $effectiveUsage['total_bytes'],
            'total_data' => $this->formatBytes($effectiveUsage['total_bytes']),
            'used_bytes' => $effectiveUsage['used_bytes'],
            'used_data' => $this->formatBytes($effectiveUsage['used_bytes']),
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

    private function preferredProviderAccount(Simcard $simcard): string
    {
        return in_array($simcard->provider_account, ['primary', 'legacy'], true)
            ? $simcard->provider_account
            : 'legacy';
    }

    private function resolveProviderAccount(Simcard $simcard, string $iccid): string
    {
        $resolved = $this->provider->resolveAccountForEsim(null, $iccid, $this->preferredProviderAccount($simcard));

        if ($resolved !== $simcard->provider_account) {
            $simcard->provider_account = $resolved;
            $simcard->save();
        }

        return $resolved;
    }

    private function normalizeTopupPlans(array $providerResponse): array
    {
        $packages = $this->extractPackageList($providerResponse);
        $plans = [];

        Log::info('Normalizing provider top-up packages.', [
            'package_count' => count($packages),
        ]);

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $providerSalePackageCode = $this->stringFromKeys($package, ['packageCode', 'package_code', 'code', 'sku']);
            $slug = $this->stringFromKeys($package, ['slug', 'packageSlug', 'package_slug']);
            $explicitTopupPackageCode = $this->firstTopupPackageCode($package, $providerSalePackageCode);

            if ($explicitTopupPackageCode !== null) {
                $providerTopupValue = $explicitTopupPackageCode;
                $topupValueType = 'package_code';
            } elseif ($slug !== null) {
                // A slug is accepted by eSIMAccess for a compatible TOPUP result.
                // It must never be taken from the unfiltered BASE catalogue.
                $providerTopupValue = $slug;
                $topupValueType = 'slug';
            } else {
                Log::debug('Provider package skipped because no top-up value or slug was found.', [
                    'provider_sale_package_code' => $providerSalePackageCode,
                    'package_keys' => array_keys($package),
                ]);

                continue;
            }

            // Preserve the public slug used by existing Stellar clients while retaining the
            // provider-authoritative TOPUP_* package code for fulfillment.
            $publicPackageCode = $slug ?? $providerTopupValue;

            $plan = $this->normalizeProviderPlan($package, $publicPackageCode);
            $plan['package_code'] = $publicPackageCode;
            $plan['code'] = $publicPackageCode;
            $plan['sku'] = $publicPackageCode;
            $plan['provider_topup_value'] = $providerTopupValue;
            $plan['provider_topup_code'] = $explicitTopupPackageCode;
            $plan['provider_topup_slug'] = $slug;
            $plan['provider_sale_package_code'] = $providerSalePackageCode;
            $plan['topup_payload_type'] = $topupValueType;
            $plan['topup_payload_field'] = 'packageCode';
            $plan['is_explicit_provider_topup_code'] = $explicitTopupPackageCode !== null;

            $plans[] = array_filter($plan, static fn ($value) => $value !== null && $value !== '');
        }

        Log::info('Provider top-up packages normalized.', [
            'input_package_count' => count($packages),
            'output_plan_count' => count($plans),
            'output_package_codes' => array_values(array_filter(array_map(
                static fn (array $plan): ?string => isset($plan['package_code']) ? (string) $plan['package_code'] : null,
                $plans
            ))),
        ]);

        return array_values($plans);
    }

    private function normalizeProviderPlan(array $package, string $fallbackCode): array
    {
        $name = $this->stringFromKeys($package, ['packageName', 'name', 'title', 'label']);
        $volumeBytes = $this->intFromKeys($package, ['volume', 'totalVolume', 'dataVolume', 'data', 'amount']);
        $durationDays = $this->intFromKeys($package, ['duration', 'durationDay', 'duration_days', 'validity', 'validityDays', 'validity_days', 'days']);
        $providerPriceCents = $this->priceCentsFromPackage($package);
        $providerCurrency = strtoupper($this->stringFromKeys($package, ['currency', 'currencyCode', 'priceCurrency', 'price_currency']) ?? 'USD');
        $locationCode = $this->stringFromKeys($package, ['locationCode', 'location_code']);
        $location = $this->stringFromKeys($package, ['location']);
        $locationName = $this->stringFromKeys($package, ['locationName', 'locationNetworkList.0.locationName']);
        $speed = $this->stringFromKeys($package, ['speed']);
        $providerPackageCode = $this->stringFromKeys($package, ['packageCode', 'package_code', 'code', 'sku']);
        $providerSlug = $this->stringFromKeys($package, ['slug', 'packageSlug', 'package_slug']);
        $supportTopupType = $this->intFromKeys($package, ['supportTopUpType', 'support_top_up_type', 'support_topup_type']);
        $dataType = $this->intFromKeys($package, ['dataType', 'data_type']);

        return array_filter([
            'package_code' => $fallbackCode,
            'code' => $fallbackCode,
            'sku' => $fallbackCode,
            'name' => $name ?? $fallbackCode,
            'package_name' => $name ?? $fallbackCode,
            'data_gb' => $this->bytesToGb($volumeBytes),
            'data_bytes' => $volumeBytes,
            'duration_days' => $durationDays,
            'validity_days' => $durationDays,
            'price_cents' => $providerPriceCents,
            'unit_price_cents' => $providerPriceCents,
            'currency' => $providerCurrency,
            'provider_price_cents' => $providerPriceCents,
            'provider_currency' => $providerCurrency,
            'pricing_source' => 'provider_raw',
            'location_code' => $locationCode,
            'location' => $location,
            'location_name' => $locationName,
            'speed' => $speed,
            'provider_package_code' => $providerPackageCode,
            'provider_slug' => $providerSlug,
            'support_topup_type' => $supportTopupType,
            'data_type' => $dataType,
            'raw' => $package,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function firstTopupPackageCode(array $package, ?string $fallbackCode = null): ?string
    {
        $candidates = [
            'topupPackageCode',
            'topUpPackageCode',
            'topup_package_code',
            'top_up_package_code',
            'rechargePackageCode',
            'recharge_package_code',
            'rechargeCode',
            'topupCode',
            'topUpCode',
            'packageCode',
            'package_code',
            'code',
            'sku',
        ];

        foreach ($candidates as $key) {
            $value = $this->stringFromKeys($package, [$key]);

            if ($value !== null && str_starts_with(strtoupper($value), 'TOPUP_')) {
                return $value;
            }
        }

        if ($fallbackCode !== null && str_starts_with(strtoupper($fallbackCode), 'TOPUP_')) {
            return $fallbackCode;
        }

        return null;
    }

    private function extractPackageList(array $response): array
    {
        $paths = [
            'obj.packageList',
            'data.obj.packageList',
            'data.packageList',
            'packageList',
            'obj.packages',
            'data.packages',
            'packages',
            'plans',
        ];

        foreach ($paths as $path) {
            $packageList = Arr::get($response, $path);

            Log::debug('Inspecting provider package-list path.', [
                'path' => $path,
                'value_type' => get_debug_type($packageList),
                'is_array' => is_array($packageList),
                'count' => is_array($packageList) ? count($packageList) : null,
                'keys' => is_array($packageList) ? array_slice(array_keys($packageList), 0, 20) : null,
            ]);

            if (is_array($packageList)) {
                Log::info('Provider package list extracted.', [
                    'path' => $path,
                    'package_count' => count($packageList),
                    'first_package_keys' => isset($packageList[array_key_first($packageList)])
                    && is_array($packageList[array_key_first($packageList)])
                        ? array_keys($packageList[array_key_first($packageList)])
                        : [],
                ]);

                return array_values($packageList);
            }
        }

        Log::warning('No provider package list could be extracted.', [
            'response_keys' => array_keys($response),
            'response_shape' => $this->describeArrayShape($response),
            'success' => Arr::get($response, 'success'),
            'error_code' => Arr::get($response, 'errorCode') ?? Arr::get($response, 'code'),
            'error_message' => Arr::get($response, 'errorMsg') ?? Arr::get($response, 'message'),
        ]);

        return [];
    }

    private function describeArrayShape(array $value, int $depth = 0): array
    {
        if ($depth >= 3) {
            return ['type' => 'array', 'count' => count($value)];
        }

        $shape = [];

        foreach (array_slice($value, 0, 20, true) as $key => $item) {
            if (is_array($item)) {
                $shape[(string) $key] = [
                    'type' => 'array',
                    'count' => count($item),
                    'children' => $this->describeArrayShape($item, $depth + 1),
                ];
            } else {
                $shape[(string) $key] = [
                    'type' => get_debug_type($item),
                ];
            }
        }

        return $shape;
    }

    private function fixedTopupPlans(array $plans): array
    {
        // TOPUP + ICCID already returns packages compatible with that eSIM.
        // This flow intentionally handles fixed-data plans only; Day Pass plans use
        // the separate esimTranNo + base slug + periodNum contract.
        return array_values(array_filter(
            $plans,
            static fn (array $plan): bool => (int) ($plan['data_type'] ?? 0) === 1
        ));
    }

    private function planLocationCode(array $plan): ?string
    {
        return $this->stringFromKeys($plan, [
            'location_code',
            'locationCode',
            'raw.locationCode',
            'raw.location_code',
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
            foreach ([
                'package_code',
                'code',
                'sku',
                'provider_topup_value',
                'provider_topup_code',
                'provider_topup_slug',
                'provider_package_code',
                'provider_slug',
            ] as $key) {
                $candidate = $plan[$key] ?? null;

                if (is_string($candidate) && $candidate !== '' && hash_equals($candidate, $packageCode)) {
                    return $plan;
                }
            }
        }

        return null;
    }

    private function customerTopupPlan(array $plan): array
    {
        $priceCents = (int) ($plan['price_cents'] ?? $plan['unit_price_cents'] ?? 0);
        $sourceCurrency = strtoupper(trim((string) ($plan['currency'] ?? '')));
        $providerCurrency = strtoupper(trim((string) ($plan['provider_currency'] ?? '')));

        if ($sourceCurrency === '') {
            $sourceCurrency = $providerCurrency !== '' ? $providerCurrency : 'USD';
        }

        $plan['currency'] = 'EUR';
        $plan['customer_currency'] = 'EUR';

        if ($priceCents > 0) {
            $plan['price_cents'] = $priceCents;
            $plan['unit_price_cents'] = $priceCents;
            $plan['customer_price_cents'] = $priceCents;
        }

        if (! isset($plan['original_currency']) || trim((string) $plan['original_currency']) === '') {
            $plan['original_currency'] = $providerCurrency !== '' ? $providerCurrency : $sourceCurrency;
        }

        if (! isset($plan['original_price_cents']) && $priceCents > 0) {
            $plan['original_price_cents'] = (int) ($plan['provider_price_cents'] ?? $priceCents);
        }

        $pricingSource = trim((string) ($plan['pricing_source'] ?? ''));
        if ($pricingSource === '' || $pricingSource === 'provider_raw') {
            $plan['pricing_source'] = 'simcard_api_eur';
        }

        if (! isset($plan['pricing_version']) || trim((string) $plan['pricing_version']) === '') {
            $plan['pricing_version'] = 'topup_eur_v1';
        }

        return array_filter($plan, static fn ($value) => $value !== null && $value !== '');
    }

    private function applyTrustedCustomerPricing(array $providerPlan, array $selectedPlan): array
    {
        if ($selectedPlan === []) {
            return $providerPlan;
        }

        $providerCode = (string) ($providerPlan['package_code'] ?? $providerPlan['code'] ?? $providerPlan['sku'] ?? '');
        $selectedCode = (string) ($selectedPlan['package_code'] ?? $selectedPlan['code'] ?? $selectedPlan['sku'] ?? '');

        if ($providerCode === '' || $selectedCode === '' || $providerCode !== $selectedCode) {
            return $providerPlan;
        }

        $source = (string) ($selectedPlan['pricing_source'] ?? '');
        $currency = strtoupper(trim((string) ($selectedPlan['currency'] ?? '')));
        $priceCents = $selectedPlan['price_cents'] ?? $selectedPlan['unit_price_cents'] ?? null;

        if ($source !== 'stellar_data_ui_api' || $currency !== 'EUR' || ! is_numeric($priceCents) || (int) $priceCents <= 0) {
            return $providerPlan;
        }

        $providerPlan['price_cents'] = (int) $priceCents;
        $providerPlan['unit_price_cents'] = (int) $priceCents;
        $providerPlan['currency'] = 'EUR';
        $providerPlan['customer_price_cents'] = (int) $priceCents;
        $providerPlan['customer_currency'] = 'EUR';
        $providerPlan['pricing_source'] = 'stellar_data_ui_api';
        $providerPlan['pricing_version'] = (string) ($selectedPlan['pricing_version'] ?? 'eur_40off_v1');
        $providerPlan['price_discount_percent'] = $selectedPlan['price_discount_percent'] ?? null;
        $providerPlan['price_fx_rate'] = $selectedPlan['price_fx_rate'] ?? null;
        $providerPlan['price_fx_source'] = $selectedPlan['price_fx_source'] ?? null;
        $providerPlan['original_price_cents'] = $selectedPlan['original_price_cents'] ?? ($providerPlan['provider_price_cents'] ?? null);
        $providerPlan['original_currency'] = $selectedPlan['original_currency'] ?? ($providerPlan['provider_currency'] ?? null);

        return array_filter($providerPlan, static fn ($value) => $value !== null && $value !== '');
    }

    private function safePlanPayload(array $plan): array
    {
        unset($plan['raw']);

        return $plan;
    }

    private function extractSupplierReference(array $payload): ?string
    {
        foreach ([
                     'obj.topUpEsimTranNo',
                     'data.topUpEsimTranNo',
                     'topUpEsimTranNo',
                     'obj.orderNo',
                     'data.orderNo',
                     'orderNo',
                     'obj.transactionId',
                     'data.transactionId',
                     'transactionId',
                 ] as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractProviderTopupReference(array $payload): ?string
    {
        foreach (['obj.topUpEsimTranNo', 'data.topUpEsimTranNo', 'topUpEsimTranNo'] as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractProviderOrderReference(array $payload): ?string
    {
        foreach (['obj.orderNo', 'data.orderNo', 'orderNo'] as $key) {
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
        $cents = $this->intFromKeys($package, [
            'priceCents',
            'price_cents',
            'unit_price_cents',
            'amount_cents',
        ]);

        if ($cents === null) {
            foreach (['price', 'unitPrice', 'unit_price', 'amount'] as $key) {
                $value = Arr::get($package, $key);

                if ($value !== null && $value !== '' && is_numeric($value)) {
                    $cents = max(0, (int) round(((float) $value) / 10));
                    break;
                }
            }
        }

        if ($cents === null) {
            return null;
        }

        // 25% discount
        return max(1, (int) round($cents * 0.75));
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
