<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Models\SimcardAutoTopupAttempt;
use App\Services\Esim\EsimCryptoService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EsimAutoTopupManagementService
{
    private const STATE_ARMED = 'ARMED';
    private const STATE_PROCESSING = 'PROCESSING';
    private const STATE_WAITING_REARM = 'WAITING_REARM';
    private const STATE_PAUSED = 'PAUSED';
    private const STATE_DISABLED = 'DISABLED';

    private const ACTIVE_ATTEMPT_STATUSES = [
        'CLAIMED',
        'EXECUTING',
        'RETRYABLE',
        'PAYMENT_PENDING',
    ];

    public function __construct(
        private readonly EsimCryptoService $crypto,
    ) {}

    /** @return array<string,mixed> */
    public function statusByPlanId(string $planId): array
    {
        $simcard = $this->resolveSimcard($planId);
        $config = SimcardAutoTopup::query()->where('simcard_id', $simcard->id)->first();

        if (! $this->hasCommerceBinding($simcard)) {
            return $this->buildStatus($simcard, $config, null, 'commerce_purchase_unavailable');
        }

        try {
            $commerce = $this->commerceStatus($simcard);

            // A local customer disable is authoritative for cycle creation. Retry a
            // previously failed Commerce revocation whenever the customer opens the
            // data app, but never turn the local switch back on from a GET request.
            $configMeta = is_array($config?->meta) ? $config->meta : [];
            if (
                $config !== null
                && ! $config->enabled
                && ! empty($configMeta['disabled_by_customer_at'])
                && ((bool) ($commerce['enabled'] ?? false) || ! empty($configMeta['authorization_sync_pending']))
            ) {
                try {
                    $commerce = $this->commerceSetEnabled(
                        simcard: $simcard,
                        enabled: false,
                        consent: false,
                        source: (string) ($configMeta['last_consent_source'] ?? 'data_website'),
                        version: (string) ($configMeta['last_consent_version'] ?? '1'),
                    );
                    $this->markAuthorizationSynced((string) $config->id);
                    $config = $config->fresh();
                } catch (Throwable $exception) {
                    Log::warning('Auto Top-Up Commerce revocation is still pending.', [
                        'simcard_id' => (string) $simcard->id,
                        'exception' => basename(str_replace('\\', '/', get_class($exception))),
                    ]);
                }
            }

            // Self-heal the rare case where Commerce has valid checkout/explicit
            // consent but provisioning did not persist the local ARMED config.
            if ($config === null && (bool) ($commerce['enabled'] ?? false) && (bool) ($commerce['can_enable'] ?? false)) {
                $config = $this->persistEnabledConfig($simcard, $commerce);
            }

            // An explicit Commerce revocation is a second fail-closed gate. If it
            // exists while the local config is still enabled, stop future cycles.
            if ($config !== null && $config->enabled && ! (bool) ($commerce['enabled'] ?? false)) {
                $config = $this->disableLocal($config, 'commerce_authorization_revoked', false);
            }

            return $this->buildStatus($simcard, $config?->fresh(), $commerce);
        } catch (Throwable $exception) {
            Log::warning('Auto Top-Up management status could not reach Commerce.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return $this->buildStatus($simcard, $config, null, 'temporarily_unavailable');
        }
    }

    /** @return array<string,mixed> */
    public function manageByPlanId(
        string $planId,
        bool $enabled,
        bool $consent,
        string $source = 'data_website',
        string $version = '1',
    ): array {
        $simcard = $this->resolveSimcard($planId);

        if (! $this->hasCommerceBinding($simcard)) {
            throw new RuntimeException('Auto Top-Up is not available for this eSIM purchase.', 409);
        }

        $source = trim($source) !== '' ? mb_substr(trim($source), 0, 64) : 'data_website';
        $version = trim($version) !== '' ? mb_substr(trim($version), 0, 32) : '1';

        if ($enabled) {
            if (! $consent) {
                throw new RuntimeException('Auto Top-Up consent is required.', 422);
            }

            // Commerce is the payment authorization boundary. The local config is
            // only enabled after Commerce confirms the saved card and price snapshot.
            $commerce = $this->commerceSetEnabled($simcard, true, true, $source, $version);
            if (! (bool) ($commerce['enabled'] ?? false) || ! (bool) ($commerce['can_enable'] ?? false)) {
                throw new RuntimeException('Auto Top-Up could not be enabled for this eSIM.', 409);
            }

            $config = $this->persistEnabledConfig($simcard, $commerce, $source, $version);

            return $this->buildStatus($simcard, $config, $commerce);
        }

        // Disable locally first. This atomically prevents every new cycle from
        // being claimed even if Commerce is temporarily unreachable. An attempt
        // already in PROCESSING remains idempotently resolvable.
        $config = SimcardAutoTopup::query()->where('simcard_id', $simcard->id)->first();
        if ($config !== null) {
            $config = $this->disableLocal($config, 'customer_disabled', true, $source, $version);
        } else {
            // Persist a local revocation tombstone before the network call. This
            // prevents a delayed provisioning retry or a later status request from
            // recreating an enabled checkout configuration if Commerce is down.
            $config = $this->persistDisabledTombstone($simcard, $source, $version);
        }

        try {
            $commerce = $this->commerceSetEnabled($simcard, false, false, $source, $version);
            if ($config !== null) {
                $this->markAuthorizationSynced((string) $config->id);
                $config = $config->fresh();
            }

            return $this->buildStatus($simcard, $config, $commerce);
        } catch (Throwable $exception) {
            Log::warning('Auto Top-Up was disabled locally but Commerce revocation is pending.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return $this->buildStatus($simcard, $config, null, 'authorization_sync_pending');
        }
    }

    /** @param array<string,mixed> $commerce */
    private function persistEnabledConfig(
        Simcard $simcard,
        array $commerce,
        string $source = 'commerce_reconciliation',
        string $version = '1',
    ): SimcardAutoTopup {
        $dataBytes = (int) ($commerce['data_bytes'] ?? 0);
        if ($dataBytes <= 0) {
            throw new RuntimeException('Auto Top-Up data allowance is unavailable.', 409);
        }

        return DB::transaction(function () use ($simcard, $commerce, $source, $version, $dataBytes): SimcardAutoTopup {
            $lockedSimcard = Simcard::query()
                ->where('id', $simcard->id)
                ->lockForUpdate()
                ->first();

            if ($lockedSimcard === null) {
                throw new RuntimeException('eSIM was not found.', 404);
            }

            if (! $this->hasCommerceBinding($lockedSimcard)) {
                throw new RuntimeException('Auto Top-Up is not available for this eSIM purchase.', 409);
            }

            $config = SimcardAutoTopup::query()
                ->where('simcard_id', $lockedSimcard->id)
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                $config = new SimcardAutoTopup();
                $config->id = (string) Str::uuid();
                $config->simcard_id = (string) $simcard->id;
                $config->cycle = 1;
                $config->state = self::STATE_ARMED;
            }

            if (
                $config->exists
                && (
                    (string) $config->parent_commerce_order_id !== (string) $lockedSimcard->commerce_order_id
                    || (string) $config->parent_commerce_order_item_id !== (string) $lockedSimcard->commerce_order_item_id
                    || (int) $config->commerce_unit !== (int) $lockedSimcard->commerce_unit
                )
            ) {
                throw new RuntimeException('Auto Top-Up is already bound to another Commerce purchase.', 409);
            }

            // Do not lock the attempt after the config: fulfillment/payment
            // callbacks lock attempt -> config. A plain snapshot here avoids a
            // lock-order inversion; the callback's final config update remains
            // authoritative if it races this enable request.
            $latestAttempt = SimcardAutoTopupAttempt::query()
                ->where('auto_topup_id', $config->id)
                ->orderByDesc('cycle')
                ->first();

            $meta = is_array($config->meta) ? $config->meta : [];
            $stateBeforeDisable = strtoupper(trim((string) ($meta['state_before_disable'] ?? '')));

            if ($latestAttempt !== null && in_array((string) $latestAttempt->status, self::ACTIVE_ATTEMPT_STATUSES, true)) {
                $config->cycle = max(1, (int) $latestAttempt->cycle);
                $config->state = self::STATE_PROCESSING;
            } elseif (
                $latestAttempt !== null
                && (string) $latestAttempt->status === 'FULFILLED'
                && $stateBeforeDisable === self::STATE_WAITING_REARM
            ) {
                $config->cycle = max(1, (int) $latestAttempt->cycle);
                $config->state = self::STATE_WAITING_REARM;
            } elseif ($latestAttempt !== null && (string) $latestAttempt->status === 'FAILED') {
                $config->cycle = max((int) $config->cycle, (int) $latestAttempt->cycle + 1);
                $config->state = self::STATE_ARMED;
            } elseif ($stateBeforeDisable === self::STATE_WAITING_REARM) {
                $config->state = self::STATE_WAITING_REARM;
            } else {
                $config->state = self::STATE_ARMED;
            }

            $config->parent_commerce_order_id = (string) $lockedSimcard->commerce_order_id;
            $config->parent_commerce_order_item_id = (string) $lockedSimcard->commerce_order_item_id;
            $config->commerce_unit = max(1, (int) $lockedSimcard->commerce_unit);
            $config->enabled = true;
            $config->trigger_percent = 35;
            $config->preferred_data_bytes = $dataBytes;
            $config->preferred_duration_days = isset($commerce['duration_days']) && is_numeric($commerce['duration_days'])
                ? max(1, (int) $commerce['duration_days'])
                : null;
            $config->failure_reason = null;

            unset(
                $meta['disabled_by_customer_at'],
                $meta['authorization_revoked_at'],
                $meta['state_before_disable'],
                $meta['authorization_sync_pending'],
                $meta['authorization_sync_failure']
            );

            $config->meta = array_merge($meta, [
                'version' => 2,
                'pricing_basis' => 'original_variant_regular_price',
                'trigger_semantics' => 'first_observed_at_or_below_threshold',
                'authorization_source' => (string) ($commerce['authorization_source'] ?? $source),
                'authorization_enabled_at' => now()->toIso8601String(),
                'last_consent_source' => $source,
                'last_consent_version' => $version,
            ]);
            $config->save();

            return $config->fresh();
        });
    }

    private function persistDisabledTombstone(
        Simcard $simcard,
        string $source,
        string $version,
    ): SimcardAutoTopup {
        return DB::transaction(function () use ($simcard, $source, $version): SimcardAutoTopup {
            $lockedSimcard = Simcard::query()
                ->where('id', $simcard->id)
                ->lockForUpdate()
                ->first();

            if ($lockedSimcard === null) {
                throw new RuntimeException('eSIM was not found.', 404);
            }

            if (! $this->hasCommerceBinding($lockedSimcard)) {
                throw new RuntimeException('Auto Top-Up is not available for this eSIM purchase.', 409);
            }

            $config = SimcardAutoTopup::query()
                ->where('simcard_id', $lockedSimcard->id)
                ->lockForUpdate()
                ->first();

            if ($config !== null) {
                return $this->applyDisabledState($config, 'customer_disabled', true, $source, $version);
            }

            $config = new SimcardAutoTopup();
            $config->id = (string) Str::uuid();
            $config->simcard_id = (string) $lockedSimcard->id;
            $config->parent_commerce_order_id = (string) $lockedSimcard->commerce_order_id;
            $config->parent_commerce_order_item_id = (string) $lockedSimcard->commerce_order_item_id;
            $config->commerce_unit = max(1, (int) $lockedSimcard->commerce_unit);
            $config->enabled = false;
            $config->state = self::STATE_DISABLED;
            $config->trigger_percent = 35;
            $config->preferred_data_bytes = max(1, (int) ($lockedSimcard->total_volume ?? 0));
            $config->preferred_duration_days = null;
            $config->cycle = 1;
            $config->failure_reason = null;
            $config->meta = [
                'version' => 2,
                'disabled_by_customer_at' => now()->toIso8601String(),
                'disabled_reason' => 'customer_disabled',
                'disabled_at' => now()->toIso8601String(),
                'authorization_sync_pending' => true,
                'last_consent_source' => $source,
                'last_consent_version' => $version,
                'pricing_basis' => 'original_variant_regular_price',
                'trigger_semantics' => 'first_observed_at_or_below_threshold',
            ];
            $config->save();

            return $config->fresh();
        });
    }

    private function disableLocal(
        SimcardAutoTopup $config,
        string $reason,
        bool $customerAction,
        string $source = 'data_website',
        string $version = '1',
    ): SimcardAutoTopup {
        return DB::transaction(function () use ($config, $reason, $customerAction, $source, $version): SimcardAutoTopup {
            $locked = SimcardAutoTopup::query()
                ->where('id', $config->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->applyDisabledState($locked, $reason, $customerAction, $source, $version);
        });
    }

    private function applyDisabledState(
        SimcardAutoTopup $locked,
        string $reason,
        bool $customerAction,
        string $source,
        string $version,
    ): SimcardAutoTopup {
        $meta = is_array($locked->meta) ? $locked->meta : [];
        $currentState = strtoupper(trim((string) $locked->state));

        if ($currentState !== self::STATE_DISABLED) {
            $meta['state_before_disable'] = $currentState;
        }

        if ($customerAction) {
            $meta['disabled_by_customer_at'] = now()->toIso8601String();
            $meta['authorization_sync_pending'] = true;
            $meta['last_consent_source'] = $source;
            $meta['last_consent_version'] = $version;
        } elseif ($reason === 'commerce_authorization_revoked') {
            $meta['authorization_revoked_at'] = now()->toIso8601String();
        }

        $meta['disabled_reason'] = $reason;
        $meta['disabled_at'] = now()->toIso8601String();

        $locked->enabled = false;
        if ($currentState !== self::STATE_PROCESSING) {
            $locked->state = self::STATE_DISABLED;
        }
        $locked->failure_reason = null;
        $locked->meta = $meta;
        $locked->save();

        return $locked->fresh();
    }

    private function markAuthorizationSynced(string $configId): void
    {
        DB::transaction(function () use ($configId): void {
            $config = SimcardAutoTopup::query()->where('id', $configId)->lockForUpdate()->first();
            if ($config === null) {
                return;
            }

            $meta = is_array($config->meta) ? $config->meta : [];
            unset($meta['authorization_sync_pending'], $meta['authorization_sync_failure']);
            $meta['authorization_synced_at'] = now()->toIso8601String();
            $config->meta = $meta;
            $config->save();
        });
    }

    /** @return array<string,mixed> */
    private function commerceStatus(Simcard $simcard): array
    {
        return $this->commerceRequest(
            (string) config('services.stellar_commerce.auto_topup_authorization_status_url', ''),
            $this->commercePayload($simcard),
        );
    }

    /** @return array<string,mixed> */
    private function commerceSetEnabled(
        Simcard $simcard,
        bool $enabled,
        bool $consent,
        string $source,
        string $version,
    ): array {
        return $this->commerceRequest(
            (string) config('services.stellar_commerce.auto_topup_authorization_url', ''),
            array_merge($this->commercePayload($simcard), [
                'enabled' => $enabled,
                'consent' => $consent,
                'consent_source' => $source,
                'consent_version' => $version,
            ]),
        );
    }

    /** @return array<string,mixed> */
    private function commercePayload(Simcard $simcard): array
    {
        return [
            'parent_order_id' => (string) $simcard->commerce_order_id,
            'parent_order_item_id' => (string) $simcard->commerce_order_item_id,
            'simcard_id' => (string) $simcard->id,
            'commerce_unit' => max(1, (int) $simcard->commerce_unit),
        ];
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private function commerceRequest(string $url, array $payload): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Commerce Auto Top-Up authorization URL is not configured.', 500);
        }

        $username = (string) config('services.stellar_commerce.username', '');
        $password = (string) config('services.stellar_commerce.password', '');
        if ($username === '' || $password === '') {
            throw new RuntimeException('Commerce Auto Top-Up credentials are not configured.', 500);
        }

        $response = Http::asJson()
            ->withBasicAuth($username, $password)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(35)
            ->retry(2, 150, static fn ($exception): bool => $exception instanceof ConnectionException)
            ->post($url, $payload);

        return $this->decodeCommerceResponse($response);
    }

    /** @return array<string,mixed> */
    private function decodeCommerceResponse(Response $response): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! $response->successful()) {
            $message = trim((string) ($body['response_message'] ?? $body['message'] ?? 'Auto Top-Up authorization is unavailable.'));
            throw new RuntimeException($message, $response->status());
        }

        $data = $body['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Commerce Auto Top-Up authorization response is invalid.', 502);
        }

        return $data;
    }

    private function resolveSimcard(string $planId): Simcard
    {
        $planId = preg_replace('/\s+/', '', trim($planId)) ?? '';
        if (preg_match('/^\d{16}$/', $planId) !== 1) {
            throw new RuntimeException('SIM ID is invalid.', 422);
        }

        $simcard = Simcard::query()
            ->where('plan_id_hash', $this->crypto->derivePlanHash($planId))
            ->first();

        if ($simcard === null) {
            throw new RuntimeException('eSIM was not found.', 404);
        }

        return $simcard;
    }

    private function hasCommerceBinding(Simcard $simcard): bool
    {
        return Str::isUuid((string) $simcard->commerce_order_id)
            && Str::isUuid((string) $simcard->commerce_order_item_id)
            && (int) $simcard->commerce_unit > 0;
    }

    /** @param array<string,mixed>|null $commerce
     *  @return array<string,mixed>
     */
    private function buildStatus(
        Simcard $simcard,
        ?SimcardAutoTopup $config,
        ?array $commerce,
        ?string $fallbackReason = null,
    ): array {
        $meta = is_array($config?->meta) ? $config->meta : [];
        $state = strtoupper(trim((string) ($config?->state ?? self::STATE_DISABLED)));
        $enabled = $config !== null && (bool) $config->enabled;
        $processing = $state === self::STATE_PROCESSING;
        $authorizationSynced = empty($meta['authorization_sync_pending']);

        $dataBytes = isset($commerce['data_bytes']) && is_numeric($commerce['data_bytes'])
            ? (int) $commerce['data_bytes']
            : (int) ($config?->preferred_data_bytes ?? 0);

        $visible = (bool) ($commerce['visible'] ?? false) || $config !== null;
        $supported = (bool) ($commerce['supported'] ?? ($config !== null));
        $canEnable = (bool) ($commerce['can_enable'] ?? false);
        $reasonCode = $fallbackReason
            ?? (string) ($commerce['reason_code'] ?? ($config !== null ? 'available' : 'not_available'));

        return [
            'visible' => $visible,
            'supported' => $supported,
            'can_manage' => $enabled || $canEnable || $config !== null,
            'can_enable' => $canEnable,
            'enabled' => $enabled,
            'state' => $state,
            'processing' => $processing,
            'turn_off_pending_current_topup' => ! $enabled && $processing,
            'authorization_synced' => $authorizationSynced,
            'reason_code' => $reasonCode,
            'saved_card_available' => (bool) ($commerce['saved_card_available'] ?? false),
            'authorization_source' => $commerce['authorization_source'] ?? ($meta['authorization_source'] ?? null),
            'amount_cents' => isset($commerce['amount_cents']) && is_numeric($commerce['amount_cents'])
                ? max(0, (int) $commerce['amount_cents'])
                : null,
            'currency' => isset($commerce['currency']) ? strtoupper(trim((string) $commerce['currency'])) : null,
            'data_bytes' => max(0, $dataBytes),
            'duration_days' => isset($commerce['duration_days']) && is_numeric($commerce['duration_days'])
                ? max(1, (int) $commerce['duration_days'])
                : ($config?->preferred_duration_days !== null ? (int) $config->preferred_duration_days : null),
            'plan_name' => (string) ($commerce['plan_name'] ?? 'Stellar eSIM'),
            'cycle' => $config !== null ? max(1, (int) $config->cycle) : null,
            'esim_status' => $simcard->esim_status !== null ? strtoupper((string) $simcard->esim_status) : null,
            'last_success_at' => $config?->last_success_at?->toIso8601String(),
            'updated_at' => $config?->updated_at?->toIso8601String(),
        ];
    }
}
