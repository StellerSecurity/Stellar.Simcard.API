<?php

namespace App\Services;

use App\Exceptions\SimcardOwnershipConflictException;
use App\Jobs\FulfillVirtualEsimTopupStepJob;
use App\Models\Simcard;
use RuntimeException;

class VirtualEsimFulfillmentService
{
    public function __construct(
        private readonly VirtualEsimPlanResolver $resolver,
        private readonly SimcardService $simcards,
        private readonly TopupService $topups,
    ) {}

    /**
     * Provision the BASE eSIM and queue included TOPUP steps.
     *
     * Provider top-up calls are deliberately never executed in this HTTP flow.
     * Commerce can continue delivery immediately after the BASE eSIM is ready.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array{simcard: Simcard, install: array<string,mixed>, virtual_fulfillment: array<string,mixed>}
     * @throws SimcardOwnershipConflictException
     */
    public function orderAndCompose(
        ?int $userId,
        string $planId,
        ?string $email,
        ?string $commerceOrderId,
        ?string $commerceOrderItemId,
        ?int $commerceUnit,
        ?string $idempotencyKey,
        int $targetDataBytes,
        int $targetDurationDays,
        array $candidates,
    ): array {
        // A recipe is locked on the Simcard record before/with provider creation.
        // Retries must reuse it verbatim; changing BASE/TOPUP composition after one
        // included top-up succeeds could otherwise over-deliver data.
        $existingSimcard = $this->simcards->findByPlanId($planId);
        $storedRecipe = $existingSimcard !== null && is_array($existingSimcard->virtual_fulfillment_recipe)
            ? $existingSimcard->virtual_fulfillment_recipe
            : null;

        if ($storedRecipe !== null) {
            $recipe = $this->validateLockedRecipe($storedRecipe, $targetDataBytes, $targetDurationDays);
        } else {
            // Resolve BEFORE spending provider balance. There is deliberately no
            // next-larger-package fallback anywhere in this flow.
            $recipe = $this->resolver->resolve(
                candidates: $candidates,
                targetDataBytes: $targetDataBytes,
                targetDurationDays: $targetDurationDays,
            );
            $recipe = $this->validateLockedRecipe($recipe, $targetDataBytes, $targetDurationDays);
        }

        $recipeTopups = array_values((array) ($recipe['topups'] ?? []));
        $recipeStatus = strtoupper(trim((string) ($recipe['status'] ?? '')));
        if ($recipeStatus === 'TOPUPS_FAILED') {
            throw new RuntimeException('Virtual eSIM included top-up composition previously failed and requires reconciliation.', 409);
        }
        if ($recipeTopups !== [] && $recipeStatus !== 'FULFILLED') {
            $this->assertAsyncQueueConfigured();
        }

        $basePackageCode = trim((string) data_get($recipe, 'base.package_code', ''));
        if ($basePackageCode === '') {
            throw new RuntimeException('Virtual plan resolver did not return a base package.', 500);
        }

        $result = $this->simcards->orderAndGetInstallInfo(
            userId: $userId,
            accountRef: null,
            packageCode: $basePackageCode,
            planId: $planId,
            email: $email,
            emailSource: 'simcard_virtual_order',
            commerceOrderId: $commerceOrderId,
            commerceOrderItemId: $commerceOrderItemId,
            commerceUnit: $commerceUnit,
            idempotencyKey: $idempotencyKey,
            virtualFulfillmentRecipe: $recipe,
        );

        /** @var Simcard $simcard */
        $simcard = $result['simcard'];

        // Protect retries of orders that may have started under the old virtual-plan
        // implementation. Never add top-ups to an already-created larger package.
        if (! hash_equals($basePackageCode, (string) $simcard->package_code)) {
            throw new RuntimeException(
                'Existing eSIM package does not match the resolved virtual-plan base package. Manual reconciliation is required.',
                409,
            );
        }

        // Never overwrite a recipe that a queue worker has already advanced.
        $persistedRecipe = is_array($simcard->virtual_fulfillment_recipe)
            ? $simcard->virtual_fulfillment_recipe
            : $recipe;
        $recipe = $this->validateLockedRecipe($persistedRecipe, $targetDataBytes, $targetDurationDays);

        if ($recipeTopups === []) {
            $recipe['fulfilled_topups'] = [];
            $recipe['status'] = 'FULFILLED';
            $recipe['customer_charge_for_included_topups'] = false;
            $recipe['fulfilled_at'] = $recipe['fulfilled_at'] ?? now()->toIso8601String();
            $this->persistRecipe($simcard, $recipe);
        } else {
            $status = strtoupper(trim((string) ($recipe['status'] ?? '')));

            if (! in_array($status, ['FULFILLED', 'TOPUPS_FAILED'], true)) {
                $recipe['status'] = 'TOPUPS_QUEUED';
                $recipe['customer_charge_for_included_topups'] = false;
                $recipe['topups_queued_at'] = $recipe['topups_queued_at'] ?? now()->toIso8601String();
                $recipe['queued_topup_count'] = count($recipeTopups);
                $this->persistRecipe($simcard, $recipe);

                // The job payload contains the private plan ID only so a worker can
                // recover a delayed ICCID. ShouldBeEncrypted encrypts the entire queue
                // payload at rest. The plan ID is never written to a domain table.
                FulfillVirtualEsimTopupStepJob::dispatch(
                    (string) $simcard->id,
                    $planId,
                    1,
                    $commerceOrderId,
                    $commerceOrderItemId,
                );
            }
        }

        return [
            'simcard' => $simcard->fresh(),
            'install' => $result['install'],
            'virtual_fulfillment' => $recipe,
        ];
    }

    /**
     * Execute exactly one included TOPUP in a queue worker.
     *
     * Returning the next step lets the job enqueue one provider mutation at a time,
     * so a virtual plan with several top-ups never performs concurrent provider writes.
     */
    public function fulfillQueuedTopupStep(
        string $simcardId,
        string $planId,
        int $step,
        ?string $commerceOrderId = null,
        ?string $commerceOrderItemId = null,
    ): ?int {
        if ($step < 1 || $step > 10) {
            throw new RuntimeException('Virtual eSIM top-up step is invalid.', 422);
        }

        $simcard = Simcard::query()->whereKey($simcardId)->first();
        if ($simcard === null) {
            throw new RuntimeException('Virtual eSIM could not be found.', 404);
        }

        $recipe = $this->lockedRecipeFromSimcard($simcard);
        $status = strtoupper(trim((string) ($recipe['status'] ?? '')));
        if ($status === 'FULFILLED') {
            return null;
        }
        if ($status === 'TOPUPS_FAILED') {
            throw new RuntimeException('Virtual eSIM included top-up composition is marked failed.', 409);
        }

        $basePackageCode = trim((string) data_get($recipe, 'base.package_code', ''));
        if ($basePackageCode === '' || ! hash_equals($basePackageCode, (string) $simcard->package_code)) {
            throw new RuntimeException(
                'Virtual eSIM base package does not match its locked fulfillment recipe.',
                409,
            );
        }

        $recipeTopups = array_values((array) ($recipe['topups'] ?? []));
        if ($recipeTopups === []) {
            $recipe['status'] = 'FULFILLED';
            $recipe['customer_charge_for_included_topups'] = false;
            $recipe['fulfilled_at'] = now()->toIso8601String();
            $this->persistRecipe($simcard, $recipe);

            return null;
        }

        if ($step > count($recipeTopups)) {
            throw new RuntimeException('Virtual eSIM top-up step exceeds the locked recipe.', 409);
        }

        $simcard = $this->simcards->ensureProviderIccid($simcard, $planId);
        $topup = $recipeTopups[$step - 1];
        $lookupCode = trim((string) (
            $topup['provider_topup_value']
            ?? $topup['package_code']
            ?? $topup['provider_topup_slug']
            ?? ''
        ));

        if ($lookupCode === '') {
            throw new RuntimeException('Virtual plan recipe contains an invalid top-up package.', 500);
        }

        $commerceUnit = isset($simcard->commerce_unit) ? (int) $simcard->commerce_unit : null;
        $stepKey = hash('sha256', implode('|', [
            'virtual-plan-included-topup',
            (string) $simcard->id,
            (string) ($commerceOrderId ?? $simcard->commerce_order_id ?? ''),
            (string) ($commerceOrderItemId ?? $simcard->commerce_order_item_id ?? ''),
            (string) ($commerceUnit ?? 1),
            (string) $step,
            $lookupCode,
        ]));

        $recipe['status'] = 'TOPUPS_PROCESSING';
        $recipe['current_topup_step'] = $step;
        $recipe['customer_charge_for_included_topups'] = false;
        $this->persistRecipe($simcard, $recipe);

        $session = $this->topups->prepareIncludedVirtualTopupSession(
            simcard: $simcard,
            packageCode: $lookupCode,
            idempotencyKey: $stepKey,
            commerceOrderId: $commerceOrderId ?? $simcard->commerce_order_id,
            commerceOrderItemId: $commerceOrderItemId ?? $simcard->commerce_order_item_id,
            commerceUnit: $commerceUnit,
            step: $step,
        );

        $fulfill = $this->topups->fulfill(
            topupSessionId: (string) $session->id,
            commerceOrderId: $commerceOrderId ?? $simcard->commerce_order_id,
            commerceOrderItemId: $commerceOrderItemId ?? $simcard->commerce_order_item_id,
            idempotencyKey: $stepKey,
        );

        $fulfilledTopups = is_array($recipe['fulfilled_topups'] ?? null)
            ? array_values($recipe['fulfilled_topups'])
            : [];

        // Replace an existing record for the same step instead of appending duplicates
        // when a queue retry re-enters after the provider already accepted the mutation.
        $fulfilledTopups = array_values(array_filter(
            $fulfilledTopups,
            static fn (mixed $entry): bool => ! is_array($entry) || (int) ($entry['step'] ?? 0) !== $step,
        ));
        $fulfilledTopups[] = [
            'step' => $step,
            'package_code' => (string) $session->package_code,
            'data_bytes' => (int) ($session->data_bytes ?? 0),
            'duration_days' => (int) ($session->duration_days ?? 0),
            'status' => (string) ($fulfill['status'] ?? 'FULFILLED'),
            'topup_session_id' => (string) $session->id,
        ];

        usort($fulfilledTopups, static fn (array $left, array $right): int => ((int) ($left['step'] ?? 0)) <=> ((int) ($right['step'] ?? 0)));

        $recipe['fulfilled_topups'] = $fulfilledTopups;
        $recipe['last_completed_topup_step'] = $step;
        $recipe['last_completed_topup_at'] = now()->toIso8601String();
        $recipe['customer_charge_for_included_topups'] = false;
        unset($recipe['last_queue_error'], $recipe['last_queue_error_at']);

        if ($step >= count($recipeTopups)) {
            $recipe['status'] = 'FULFILLED';
            $recipe['fulfilled_at'] = now()->toIso8601String();
            unset($recipe['current_topup_step'], $recipe['retry_topup_step']);
            $this->persistRecipe($simcard, $recipe);

            return null;
        }

        $recipe['status'] = 'TOPUPS_QUEUED';
        $recipe['current_topup_step'] = $step + 1;
        $this->persistRecipe($simcard, $recipe);

        return $step + 1;
    }

    public function markQueuedTopupsRetrying(string $simcardId, int $step, string $reason): void
    {
        $simcard = Simcard::query()->whereKey($simcardId)->first();
        if ($simcard === null || ! is_array($simcard->virtual_fulfillment_recipe)) {
            return;
        }

        $recipe = $simcard->virtual_fulfillment_recipe;
        if (strtoupper(trim((string) ($recipe['status'] ?? ''))) === 'FULFILLED') {
            return;
        }

        $recipe['status'] = 'TOPUPS_RETRYING';
        $recipe['retry_topup_step'] = $step;
        $recipe['last_queue_error'] = mb_substr($reason, 0, 500);
        $recipe['last_queue_error_at'] = now()->toIso8601String();
        $this->persistRecipe($simcard, $recipe);
    }

    public function markQueuedTopupsFailed(string $simcardId, int $step, string $reason): void
    {
        $simcard = Simcard::query()->whereKey($simcardId)->first();
        if ($simcard === null || ! is_array($simcard->virtual_fulfillment_recipe)) {
            return;
        }

        $recipe = $simcard->virtual_fulfillment_recipe;
        if (strtoupper(trim((string) ($recipe['status'] ?? ''))) === 'FULFILLED') {
            return;
        }

        $recipe['status'] = 'TOPUPS_FAILED';
        $recipe['failed_topup_step'] = $step;
        $recipe['last_queue_error'] = mb_substr($reason, 0, 500);
        $recipe['last_queue_error_at'] = now()->toIso8601String();
        $recipe['failed_at'] = now()->toIso8601String();
        $this->persistRecipe($simcard, $recipe);
    }

    /** @return array<string,mixed> */
    private function lockedRecipeFromSimcard(Simcard $simcard): array
    {
        $recipe = is_array($simcard->virtual_fulfillment_recipe)
            ? $simcard->virtual_fulfillment_recipe
            : null;

        if ($recipe === null) {
            throw new RuntimeException('Virtual eSIM has no locked fulfillment recipe.', 409);
        }

        $targetBytes = data_get($recipe, 'target_data_bytes');
        $targetDays = data_get($recipe, 'target_duration_days');
        if (! is_numeric($targetBytes) || ! is_numeric($targetDays)) {
            throw new RuntimeException('Virtual eSIM locked recipe has invalid target values.', 409);
        }

        return $this->validateLockedRecipe($recipe, (int) $targetBytes, (int) $targetDays);
    }

    private function persistRecipe(Simcard $simcard, array $recipe): void
    {
        $simcard->virtual_fulfillment_recipe = $recipe;
        $simcard->save();
    }

    private function assertAsyncQueueConfigured(): void
    {
        $connection = trim((string) config('queue.default', ''));
        $driver = strtolower(trim((string) config('queue.connections.' . $connection . '.driver', '')));

        if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd', 'failover'], true)) {
            throw new RuntimeException(
                'Virtual eSIM included top-ups require an asynchronous persistent queue connection.',
                503,
            );
        }
    }

    /** @return array<string,mixed> */
    private function validateLockedRecipe(array $recipe, int $targetDataBytes, int $targetDurationDays): array
    {
        $basePackageCode = trim((string) data_get($recipe, 'base.package_code', ''));
        $deliveredBytes = data_get($recipe, 'delivered_data_bytes');
        $deliveredDays = data_get($recipe, 'delivered_duration_days');
        $recipeTargetBytes = data_get($recipe, 'target_data_bytes');
        $recipeTargetDays = data_get($recipe, 'target_duration_days');
        $topups = data_get($recipe, 'topups', []);

        if (
            $basePackageCode === ''
            || ! is_numeric($deliveredBytes)
            || abs((int) $deliveredBytes - $targetDataBytes) > 1048576
            || ! is_numeric($deliveredDays)
            || (int) $deliveredDays !== $targetDurationDays
            || ! is_numeric($recipeTargetBytes)
            || abs((int) $recipeTargetBytes - $targetDataBytes) > 1048576
            || ! is_numeric($recipeTargetDays)
            || (int) $recipeTargetDays !== $targetDurationDays
            || ! is_array($topups)
            || count($topups) > 10
        ) {
            throw new RuntimeException('Stored virtual-plan fulfillment recipe does not match the advertised plan.', 409);
        }

        return $recipe;
    }
}
