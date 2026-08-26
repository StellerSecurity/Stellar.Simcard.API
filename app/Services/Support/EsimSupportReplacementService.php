<?php

namespace App\Services\Support;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Models\SimcardAutoTopupAttempt;
use App\Models\SimcardTopupSession;
use App\Models\SimcardSupportReplacement;
use App\Services\Esim\EsimCryptoService;
use App\Services\SimcardService;
use App\Services\UnusedEsimCancellationService;
use App\Services\VirtualEsimFulfillmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EsimSupportReplacementService
{
    public function __construct(
        private readonly SimcardService $simcards,
        private readonly UnusedEsimCancellationService $cancellations,
        private readonly VirtualEsimFulfillmentService $virtual,
        private readonly EsimCryptoService $crypto,
    ) {}

    /** @return array<string,mixed>|null */
    public function inspect(string $planId, string $customerEmail): ?array
    {
        $planId = $this->normalizePlanId($planId);
        $email = $this->normalizeEmail($customerEmail);
        $simcard = $this->simcards->findByPlanId($planId);
        if ($simcard === null) {
            return null;
        }

        $emailMatch = $simcard->email_hash !== null
            && hash_equals((string) $simcard->email_hash, $this->crypto->deriveEmailHash($email));

        if (! $emailMatch) {
            // Do not disclose provider status or install credentials for an eSIM that
            // cannot be tied to the support sender.
            return [
                'found' => true,
                'email_match' => false,
                'used_bytes' => null,
                'eligible_to_replace' => false,
                'blocked_reason' => 'OWNERSHIP_NOT_VERIFIED',
                'provider' => [],
                'install' => [],
                'plan' => [],
            ];
        }

        $query = $this->simcards->queryStatusByPlanId($planId);
        $provider = is_array($query['provider'] ?? null) ? $query['provider'] : [];
        $usedBytes = is_numeric($provider['used_bytes'] ?? null) ? (int) $provider['used_bytes'] : null;
        $alreadyReplaced = SimcardSupportReplacement::query()
            ->where('old_simcard_id', $simcard->id)
            ->whereIn('status', ['prepared', 'old_cancelled', 'provisioning', 'completed'])
            ->exists();
        $hasAutoTopup = Schema::hasTable('simcard_auto_topups') && SimcardAutoTopup::query()
            ->where('simcard_id', $simcard->id)
            ->where('enabled', true)
            ->exists();

        $cancelled = strtolower((string) $simcard->state) === 'cancelled';
        $blockedReason = match (true) {
            $hasAutoTopup => 'AUTO_TOPUP_REQUIRES_MANUAL_REVIEW',
            $alreadyReplaced => 'ALREADY_REPLACED_OR_IN_PROGRESS',
            $cancelled => 'ALREADY_CANCELLED',
            $usedBytes === null => 'USAGE_UNKNOWN',
            $usedBytes > 0 => 'USAGE_DETECTED',
            default => null,
        };

        return [
            'found' => true,
            'email_match' => $emailMatch,
            'used_bytes' => $usedBytes,
            'eligible_to_replace' => $emailMatch
                && $usedBytes === 0
                && ! $alreadyReplaced
                && ! $hasAutoTopup
                && ! $cancelled,
            'blocked_reason' => $blockedReason,
            'lifecycle' => $this->supportLifecycle($provider),
            'provider' => $provider,
            'install' => is_array($query['install'] ?? null) ? $query['install'] : [],
            'plan' => [
                'package_code' => (string) $simcard->package_code,
                'period_num' => $simcard->provider_period_num !== null ? (int) $simcard->provider_period_num : null,
                'virtual' => is_array($simcard->virtual_fulfillment_recipe),
            ],
            'topup' => $this->topupDiagnostics($simcard),
        ];
    }

    /** @return array<string,mixed> */
    public function replaceUnused(string $planId, string $customerEmail, string $idempotencyKey): array
    {
        $planId = $this->normalizePlanId($planId);
        $email = $this->normalizeEmail($customerEmail);
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new RuntimeException('A valid support replacement idempotency key is required.', 422);
        }

        $planHash = $this->crypto->derivePlanHash($planId);
        return Cache::lock('support-esim-replacement:'.$planHash, 180)->block(10, function () use ($planId, $email, $idempotencyKey): array {
            $old = $this->simcards->findByPlanId($planId);
            if ($old === null) {
                throw new RuntimeException('eSIM was not found.', 404);
            }
            $this->assertOwnership($old, $email);
            if (Schema::hasTable('simcard_auto_topups') && SimcardAutoTopup::query()->where('simcard_id', $old->id)->where('enabled', true)->exists()) {
                throw new RuntimeException('This eSIM has Auto Top-Up enabled and requires manual replacement review.', 409);
            }

            $replacement = $this->reserveReplacement($old, $idempotencyKey);
            $newPlanId = $this->crypto->decryptSensitiveValue($replacement->new_plan_id_enc);

            if ($replacement->status === 'completed' && $replacement->new_simcard_id !== null) {
                return $this->completedPayload($replacement, $newPlanId, true);
            }

            try {
                if ($replacement->cancelled_old_at === null) {
                    // Re-query provider immediately before destructive cancellation. Exactly 0
                    // bytes is mandatory; null/unknown usage is never treated as zero here.
                    $live = $this->simcards->queryStatusByPlanId($planId);
                    $used = data_get($live, 'provider.used_bytes');
                    if (! is_numeric($used) || (int) $used !== 0) {
                        throw new RuntimeException('Replacement blocked because live provider usage is not exactly 0 bytes.', 409);
                    }
                    $this->cancellations->cancel($planId);
                    $replacement->forceFill(['status' => 'old_cancelled', 'cancelled_old_at' => now(), 'last_error' => null])->save();
                }

                $replacement->forceFill(['status' => 'provisioning', 'last_error' => null])->save();
                $result = $this->provisionLikeOriginal($old->fresh(), $newPlanId, $email, $replacement);
                /** @var Simcard $new */
                $new = $result['simcard'];
                $this->copySafeOwnershipAndCommerceMetadata($old, $new);

                $replacement->forceFill([
                    'new_simcard_id' => $new->id,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'last_error' => null,
                ])->save();

                return [
                    'success' => true,
                    'status' => 'replaced',
                    'sim_id' => $newPlanId,
                    'install' => $result['install'] ?? [],
                    'provider' => data_get($this->simcards->queryStatusByPlanId($newPlanId), 'provider', []),
                    'idempotent_replay' => false,
                ];
            } catch (Throwable $e) {
                $replacement->forceFill([
                    'status' => $replacement->cancelled_old_at !== null ? 'provisioning_failed' : 'failed',
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();
                throw $e;
            }
        });
    }

    private function reserveReplacement(Simcard $old, string $idempotencyKey): SimcardSupportReplacement
    {
        return DB::transaction(function () use ($old, $idempotencyKey): SimcardSupportReplacement {
            $existing = SimcardSupportReplacement::query()
                ->where(fn ($q) => $q->where('idempotency_key', $idempotencyKey)->orWhere('old_simcard_id', $old->id))
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_key, $idempotencyKey)) {
                    throw new RuntimeException('This eSIM already has a replacement request. Manual review is required.', 409);
                }
                return $existing;
            }

            $newPlanId = $this->generateUniquePlanId();
            return SimcardSupportReplacement::create([
                'id' => (string) Str::uuid(),
                'old_simcard_id' => $old->id,
                'idempotency_key' => $idempotencyKey,
                'new_plan_id_enc' => $this->crypto->encryptSensitiveValue($newPlanId),
                'status' => 'prepared',
            ]);
        }, 3);
    }

    /** @return array{simcard:Simcard,install:array<string,mixed>} */
    private function provisionLikeOriginal(Simcard $old, string $newPlanId, string $email, SimcardSupportReplacement $replacement): array
    {
        $recipe = is_array($old->virtual_fulfillment_recipe) ? $this->freshVirtualRecipe($old->virtual_fulfillment_recipe) : null;
        if ($recipe !== null) {
            $targetBytes = data_get($recipe, 'target_data_bytes');
            $targetDays = data_get($recipe, 'target_duration_days');
            if (! is_numeric($targetBytes) || ! is_numeric($targetDays)) {
                throw new RuntimeException('Original virtual eSIM recipe is invalid and requires manual review.', 409);
            }
            $result = $this->virtual->orderAndCompose(
                userId: null,
                planId: $newPlanId,
                email: $email,
                commerceOrderId: null,
                commerceOrderItemId: null,
                commerceUnit: null,
                idempotencyKey: 'support-replacement:'.$replacement->id,
                targetDataBytes: (int) $targetBytes,
                targetDurationDays: (int) $targetDays,
                candidates: [],
                lockedRecipe: $recipe,
            );
            return ['simcard' => $result['simcard'], 'install' => $result['install']];
        }

        return $this->simcards->orderAndGetInstallInfo(
            userId: null,
            accountRef: null,
            packageCode: (string) $old->package_code,
            planId: $newPlanId,
            email: $email,
            emailSource: 'ai_support_replacement',
            commerceOrderId: null,
            commerceOrderItemId: null,
            commerceUnit: null,
            idempotencyKey: 'support-replacement:'.$replacement->id,
            virtualFulfillmentRecipe: null,
            periodNum: $old->provider_period_num !== null ? (int) $old->provider_period_num : null,
        );
    }


    /** @return array<string,mixed> */
    private function freshVirtualRecipe(array $recipe): array
    {
        foreach ([
            'fulfilled_topups', 'fulfilled_at', 'base_provisioned_at', 'topups_queued_at',
            'queued_topup_count', 'current_topup_step', 'retry_topup_step',
            'last_completed_topup_step', 'last_completed_topup_at', 'failed_topup_step',
            'last_queue_error', 'last_queue_error_at', 'failed_at',
        ] as $key) {
            unset($recipe[$key]);
        }

        $topups = is_array($recipe['topups'] ?? null) ? $recipe['topups'] : [];
        if ($topups !== []) {
            $recipe['status'] = 'LOCKED';
        } elseif (($recipe['strategy'] ?? '') === 'quota_capped_provider_plan_v1') {
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $quota['state'] = 'MONITORING';
            $quota['paid_topup_session_ids'] = [];
            unset($quota['suspended_at'], $quota['suspend_queued_at'], $quota['last_error']);
            $recipe['quota'] = $quota;
            $recipe['status'] = 'LOCKED';
        } else {
            $recipe['status'] = 'LOCKED';
        }

        return $recipe;
    }

    private function copySafeOwnershipAndCommerceMetadata(Simcard $old, Simcard $new): void
    {
        // Do NOT copy the Commerce order/item/unit tuple to the replacement SIM.
        // The simcards table has a UNIQUE constraint on
        // (commerce_order_id, commerce_order_item_id, commerce_unit), so copying it
        // would fail after the old profile has already been cancelled. The
        // simcard_support_replacements table is the authoritative old -> new link.
        $new->forceFill([
            'user_ref' => $old->user_ref,
            'user_ref_version' => $old->user_ref_version,
            'user_linked_at' => $old->user_linked_at,
            'user_link_source' => $old->user_ref !== null ? 'support_replacement' : null,
            // Avoid sending a second marketing-reward campaign solely because support
            // replaced an unused eSIM tied to the same original purchase.
            'marketing_refund_notification_attempted_at' => now(),
        ])->save();
    }

    /** @return array<string,mixed> */
    private function completedPayload(SimcardSupportReplacement $replacement, string $newPlanId, bool $replay): array
    {
        $query = $this->simcards->queryStatusByPlanId($newPlanId);
        if ($query === null) {
            throw new RuntimeException('Completed replacement eSIM could not be reloaded.', 500);
        }
        return [
            'success' => true,
            'status' => 'replaced',
            'sim_id' => $newPlanId,
            'install' => $query['install'] ?? [],
            'provider' => $query['provider'] ?? [],
            'idempotent_replay' => $replay,
        ];
    }

    /** @return array<string,mixed> */
    private function supportLifecycle(array $provider): array
    {
        $used = is_numeric($provider['used_bytes'] ?? null) ? (int) $provider['used_bytes'] : null;
        $remaining = is_numeric($provider['remaining_bytes'] ?? null) ? (int) $provider['remaining_bytes'] : null;
        $total = is_numeric($provider['total_bytes'] ?? null) ? (int) $provider['total_bytes'] : null;
        $providerStatus = strtoupper(trim((string) ($provider['esim_status'] ?? '')));
        $expired = false;
        $expiresAt = $provider['expires_at'] ?? null;
        if (is_string($expiresAt) && trim($expiresAt) !== '') {
            try {
                $expired = Carbon::parse($expiresAt)->isPast();
            } catch (Throwable) {
                $expired = false;
            }
        }

        $state = match (true) {
            $expired => 'expired',
            $total !== null && $total > 0 && $remaining === 0 => 'exhausted',
            $used !== null && $used > 0 => 'in_use',
            $providerStatus === 'IN_USE' => 'in_use',
            $used === 0 => 'unused',
            default => 'unknown',
        };

        return [
            'state' => $state,
            'has_started_data_session' => $state === 'in_use' || $state === 'exhausted',
            'expired' => $expired,
            'exhausted' => $state === 'exhausted',
            'provider_esim_status' => $providerStatus !== '' ? $providerStatus : null,
            'provider_smdp_status' => isset($provider['smdp_status']) ? (string) $provider['smdp_status'] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function topupDiagnostics(Simcard $simcard): array
    {
        $result = [
            'auto_topup' => null,
            'recent_auto_topup_attempts' => [],
            'recent_sessions' => [],
        ];

        if (Schema::hasTable('simcard_auto_topups')) {
            $auto = SimcardAutoTopup::query()->where('simcard_id', $simcard->id)->latest('updated_at')->first();
            if ($auto !== null) {
                $result['auto_topup'] = [
                    'enabled' => (bool) $auto->enabled,
                    'state' => (string) $auto->state,
                    'trigger_percent' => $auto->trigger_percent !== null ? (int) $auto->trigger_percent : null,
                    'preferred_data_bytes' => $auto->preferred_data_bytes !== null ? (int) $auto->preferred_data_bytes : null,
                    'preferred_duration_days' => $auto->preferred_duration_days !== null ? (int) $auto->preferred_duration_days : null,
                    'cycle' => (int) ($auto->cycle ?? 0),
                    'last_triggered_at' => $auto->last_triggered_at?->toIso8601String(),
                    'last_success_at' => $auto->last_success_at?->toIso8601String(),
                    'last_rearmed_at' => $auto->last_rearmed_at?->toIso8601String(),
                    'failure_reason' => $auto->failure_reason !== null ? mb_substr((string) $auto->failure_reason, 0, 500) : null,
                ];

                if (Schema::hasTable('simcard_auto_topup_attempts')) {
                    $result['recent_auto_topup_attempts'] = SimcardAutoTopupAttempt::query()
                        ->where('auto_topup_id', $auto->id)
                        ->latest('created_at')
                        ->limit(5)
                        ->get()
                        ->map(static fn (SimcardAutoTopupAttempt $attempt): array => [
                            'cycle' => (int) $attempt->cycle,
                            'status' => (string) $attempt->status,
                            'observed_total_bytes' => $attempt->observed_total_bytes !== null ? (int) $attempt->observed_total_bytes : null,
                            'observed_remaining_bytes' => $attempt->observed_remaining_bytes !== null ? (int) $attempt->observed_remaining_bytes : null,
                            'observed_remaining_percent' => $attempt->observed_remaining_percent !== null ? (float) $attempt->observed_remaining_percent : null,
                            'payment_requested_at' => $attempt->payment_requested_at?->toIso8601String(),
                            'fulfilled_at' => $attempt->fulfilled_at?->toIso8601String(),
                            'failure_reason' => $attempt->failure_reason !== null ? mb_substr((string) $attempt->failure_reason, 0, 500) : null,
                        ])->values()->all();
                }
            }
        }

        if (Schema::hasTable('simcard_topup_sessions')) {
            $result['recent_sessions'] = SimcardTopupSession::query()
                ->where('simcard_id', $simcard->id)
                ->latest('created_at')
                ->limit(5)
                ->get()
                ->map(static fn (SimcardTopupSession $session): array => [
                    'package_name' => $session->package_name !== null ? (string) $session->package_name : null,
                    'data_bytes' => $session->data_bytes !== null ? (int) $session->data_bytes : null,
                    'duration_days' => $session->duration_days !== null ? (int) $session->duration_days : null,
                    'price_cents' => $session->price_cents !== null ? (int) $session->price_cents : null,
                    'currency' => $session->currency !== null ? strtoupper((string) $session->currency) : null,
                    'status' => (string) $session->status,
                    'requested_at' => $session->requested_at?->toIso8601String(),
                    'paid_at' => $session->paid_at?->toIso8601String(),
                    'fulfilled_at' => $session->fulfilled_at?->toIso8601String(),
                    'failure_reason' => $session->failure_reason !== null ? mb_substr((string) $session->failure_reason, 0, 500) : null,
                ])->values()->all();
        }

        return $result;
    }

    private function assertOwnership(Simcard $simcard, string $email): void
    {
        if ($simcard->email_hash === null || ! hash_equals((string) $simcard->email_hash, $this->crypto->deriveEmailHash($email))) {
            throw new RuntimeException('eSIM ownership could not be verified against the customer email.', 403);
        }
    }

    private function generateUniquePlanId(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $planId = (string) random_int(1000000000000000, 9999999999999999);
            if ($this->simcards->findByPlanId($planId) === null) {
                return $planId;
            }
        }
        throw new RuntimeException('Could not allocate a unique replacement SIM ID.', 500);
    }

    private function normalizePlanId(string $planId): string
    {
        $planId = preg_replace('/\s+/', '', trim($planId)) ?? '';
        if (preg_match('/^\d{16}$/', $planId) !== 1) {
            throw new RuntimeException('SIM ID must be exactly 16 digits after removing whitespace.', 422);
        }
        return $planId;
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid customer email is required.', 422);
        }
        return $email;
    }
}
