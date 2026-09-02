<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\SimcardOrderConflictException;
use App\Exceptions\SimcardOwnershipConflictException;
use App\Http\Controllers\Controller;
use App\Services\EsimAutoTopupService;
use App\Services\SimcardService;
use App\Services\VirtualEsimFulfillmentService;
use App\Services\VirtualEsimPlanResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SimcardController extends Controller
{
    private const USER_LINK_SOURCES = [
        'purchase',
        'commerce_fulfillment',
        'manual_claim',
        'account_migration',
        'support',
        'topup',
        'mobile_app',
    ];

    public function __construct(
        private readonly SimcardService $simcardService,
        private readonly EsimAutoTopupService $autoTopupService,
        private readonly VirtualEsimFulfillmentService $virtualEsimFulfillment,
        private readonly VirtualEsimPlanResolver $virtualEsimPlanResolver,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $filters = $request->only(['locationCode', 'type', 'packageCode', 'iccid', 'slug', 'dataType']);
        $plans = $this->simcardService->listPlans($filters);

        return response()->json([
            'response_code' => 200,
            'data' => $plans,
        ], 200);
    }

    /**
     * @throws ValidationException
     */
    public function order(Request $request): JsonResponse
    {
        $this->normalizePlanId($request);

        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
            'packageCode' => ['required', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'email' => ['nullable', 'email', 'max:254'],
            'commerce_order_id' => ['nullable', 'string', 'max:64'],
            'commerce_order_item_id' => ['nullable', 'string', 'max:64'],
            'commerce_unit' => ['nullable', 'integer', 'min:1', 'max:99'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            // Optional and additive. eSIMAccess Daily/Unlimited plans (dataType=2)
            // use periodNum to select the purchased validity in days.
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // Auto Top-Up is optional and must never alter normal eSIM ordering.
            // Its nested values are normalized after the provider order succeeds.
            'auto_topup' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();

        try {
            $result = $this->simcardService->orderAndGetInstallInfo(
                userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
                accountRef: null,
                packageCode: $data['packageCode'],
                planId: $data['plan_id'],
                email: $data['email'] ?? null,
                emailSource: 'simcard_order',
                commerceOrderId: $data['commerce_order_id'] ?? null,
                commerceOrderItemId: $data['commerce_order_item_id'] ?? null,
                commerceUnit: isset($data['commerce_unit']) ? (int) $data['commerce_unit'] : null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                periodNum: isset($data['days']) ? (int) $data['days'] : null,
            );
        } catch (SimcardOwnershipConflictException $exception) {
            return $this->ownershipConflict($exception->getMessage());
        } catch (SimcardOrderConflictException $exception) {
            return response()->json([
                'response_code' => 409,
                'response_message' => $exception->getMessage(),
            ], 409);
        }

        $autoTopupConfigured = false;
        $autoTopup = $data['auto_topup'] ?? null;

        if (is_array($autoTopup) && filter_var($autoTopup['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            try {
                $this->autoTopupService->configureForSimcard($result['simcard'], $autoTopup);
                $autoTopupConfigured = true;
            } catch (Throwable $exception) {
                // Auto Top-Up is additive. A configuration problem must never turn a
                // successfully purchased/provider-created eSIM into a failed fulfillment.
                Log::error('eSIM was provisioned but Auto Top-Up configuration failed.', [
                    'simcard_id' => (string) $result['simcard']->id,
                    'commerce_order_id' => (string) ($result['simcard']->commerce_order_id ?? ''),
                    'commerce_order_item_id' => (string) ($result['simcard']->commerce_order_item_id ?? ''),
                    'exception' => basename(str_replace('\\', '/', get_class($exception))),
                ]);
            }
        }

        return response()->json([
            'response_code' => 201,
            'data' => [
                'simcard' => [
                    'state' => $result['simcard']->state,
                    'provider' => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                    'plan_type' => $result['simcard']->provider_period_num !== null ? 'unlimited' : 'fixed',
                    'duration_days' => $result['simcard']->provider_period_num !== null
                        ? (int) $result['simcard']->provider_period_num
                        : null,
                    // Boolean only: confirms ownership was linked without
                    // exposing the raw user ID or privacy-preserving user_ref.
                    'account_linked' => $result['simcard']->user_ref !== null,
                    'auto_topup_configured' => $autoTopupConfigured,
                ],
                'install' => $result['install'],
            ],
        ], 201);
    }

    /**
     * Resolve a virtual Stellar plan without purchasing an eSIM.
     *
     * Used by Commerce catalog audits/preflight. A 422 response is a definitive
     * "no exact-data composition with sufficient validity" verdict; transient provider errors return 5xx.
     */
    public function virtualResolve(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'virtual_plan' => ['required', 'array'],
            'virtual_plan.target_data_bytes' => ['required', 'integer', 'min:1'],
            'virtual_plan.target_duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'virtual_plan.enforce_target_duration' => ['nullable', 'boolean'],
            'virtual_plan.candidates' => ['required', 'array', 'min:1', 'max:50'],
            'virtual_plan.candidates.*.package_code' => ['required', 'string', 'max:128'],
            'virtual_plan.candidates.*.data_bytes' => ['required', 'integer', 'min:1'],
            'virtual_plan.candidates.*.duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'virtual_plan.candidates.*.variant_id' => ['nullable', 'string', 'max:64'],
            'virtual_plan.candidates.*.name' => ['nullable', 'string', 'max:200'],
            'virtual_plan.candidates.*.active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $virtual = $validator->validated()['virtual_plan'];

        try {
            $recipe = $this->virtualEsimPlanResolver->resolve(
                candidates: (array) $virtual['candidates'],
                targetDataBytes: (int) $virtual['target_data_bytes'],
                targetDurationDays: (int) $virtual['target_duration_days'],
                enforceTargetDuration: (bool) ($virtual['enforce_target_duration'] ?? false),
            );
        } catch (RuntimeException $exception) {
            $status = (int) $exception->getCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            return response()->json([
                'response_code' => $status,
                'response_message' => $exception->getMessage(),
            ], $status);
        } catch (Throwable $exception) {
            Log::error('Virtual eSIM preflight resolution failed unexpectedly.', [
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return response()->json([
                'response_code' => 500,
                'response_message' => 'Virtual eSIM preflight resolution failed.',
            ], 500);
        }

        return response()->json([
            'response_code' => 200,
            'data' => [
                'virtual_fulfillment' => $recipe,
            ],
        ], 200);
    }

    /**
     * Provision a virtual Stellar plan with exact advertised data and at least
     * the advertised validity. Extra validity is allowed; extra data is not.
     *
     * This endpoint is intentionally separate from /order. Normal eSIM orders keep
     * their existing contract and cannot accidentally enter virtual-plan logic.
     */
    public function virtualOrder(Request $request): JsonResponse
    {
        $this->normalizePlanId($request);

        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'email' => ['nullable', 'email', 'max:254'],
            'commerce_order_id' => ['nullable', 'string', 'max:64'],
            'commerce_order_item_id' => ['nullable', 'string', 'max:64'],
            'commerce_unit' => ['nullable', 'integer', 'min:1', 'max:99'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'auto_topup' => ['nullable', 'array'],
            'virtual_plan' => ['required', 'array'],
            'virtual_plan.target_data_bytes' => ['required', 'integer', 'min:1'],
            'virtual_plan.target_duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'virtual_plan.enforce_target_duration' => ['nullable', 'boolean'],
            'virtual_plan.candidates' => ['required', 'array', 'min:1', 'max:50'],
            'virtual_plan.candidates.*.package_code' => ['required', 'string', 'max:128'],
            'virtual_plan.candidates.*.data_bytes' => ['required', 'integer', 'min:1'],
            'virtual_plan.candidates.*.duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'virtual_plan.candidates.*.variant_id' => ['nullable', 'string', 'max:64'],
            'virtual_plan.candidates.*.name' => ['nullable', 'string', 'max:200'],
            'virtual_plan.candidates.*.active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $virtual = $data['virtual_plan'];

        try {
            $result = $this->virtualEsimFulfillment->orderAndCompose(
                userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
                planId: (string) $data['plan_id'],
                email: $data['email'] ?? null,
                commerceOrderId: $data['commerce_order_id'] ?? null,
                commerceOrderItemId: $data['commerce_order_item_id'] ?? null,
                commerceUnit: isset($data['commerce_unit']) ? (int) $data['commerce_unit'] : null,
                idempotencyKey: $data['idempotency_key'] ?? null,
                targetDataBytes: (int) $virtual['target_data_bytes'],
                targetDurationDays: (int) $virtual['target_duration_days'],
                candidates: (array) $virtual['candidates'],
                enforceTargetDuration: (bool) ($virtual['enforce_target_duration'] ?? false),
            );
        } catch (SimcardOwnershipConflictException $exception) {
            return $this->ownershipConflict($exception->getMessage());
        } catch (RuntimeException $exception) {
            $status = (int) $exception->getCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            return response()->json([
                'response_code' => $status,
                'response_message' => $exception->getMessage(),
            ], $status);
        } catch (Throwable $exception) {
            Log::error('Virtual eSIM fulfillment failed unexpectedly.', [
                'commerce_order_id' => (string) ($data['commerce_order_id'] ?? ''),
                'commerce_order_item_id' => (string) ($data['commerce_order_item_id'] ?? ''),
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return response()->json([
                'response_code' => 500,
                'response_message' => 'Virtual eSIM fulfillment failed.',
            ], 500);
        }

        $autoTopupConfigured = false;
        $autoTopup = $data['auto_topup'] ?? null;

        if (is_array($autoTopup) && filter_var($autoTopup['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            try {
                $this->autoTopupService->configureForSimcard($result['simcard'], $autoTopup);
                $autoTopupConfigured = true;
            } catch (Throwable $exception) {
                // Customer Auto Top-Up remains additive and independent from the included
                // virtual-plan top-ups that were already queued independently above.
                Log::error('Virtual eSIM base was provisioned but Auto Top-Up configuration failed.', [
                    'simcard_id' => (string) $result['simcard']->id,
                    'commerce_order_id' => (string) ($result['simcard']->commerce_order_id ?? ''),
                    'commerce_order_item_id' => (string) ($result['simcard']->commerce_order_item_id ?? ''),
                    'exception' => basename(str_replace('\\', '/', get_class($exception))),
                ]);
            }
        }

        return response()->json([
            'response_code' => 201,
            'data' => [
                'simcard' => [
                    'state' => $result['simcard']->state,
                    'provider' => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                    'account_linked' => $result['simcard']->user_ref !== null,
                    'auto_topup_configured' => $autoTopupConfigured,
                ],
                'install' => $result['install'],
                'virtual_fulfillment' => $result['virtual_fulfillment'],
            ],
        ], 201);
    }

    public function query(Request $request): JsonResponse
    {
        $this->normalizePlanId($request);

        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();
        $result = $this->simcardService->queryStatusByPlanId($data['plan_id']);

        if ($result === null) {
            return response()->json([
                'response_code' => 400,
                'response_message' => 'Simcard was not found.',
            ], 400);
        }

        return response()->json([
            'response_code' => 200,
            'data' => [
                'simcard' => [
                    'state' => $result['simcard']->state,
                    'provider' => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                    'plan_type' => $result['simcard']->provider_period_num !== null ? 'unlimited' : 'fixed',
                    'duration_days' => $result['simcard']->provider_period_num !== null
                        ? (int) $result['simcard']->provider_period_num
                        : null,
                ],
                'provider' => $result['provider'],
                'install' => $result['install'] ?? [],
            ],
        ], 200);
    }

    /**
     * POST /api/v1/sim/user
     * Return all safely exposable eSIM metadata for one verified Stellar user.
     */
    public function user(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return response()->json([
            'response_code' => 200,
            'data' => $this->simcardService->listByUserId(
                (int) $validator->validated()['user_id']
            ),
        ], 200);
    }

    /**
     * PATCH /api/v1/sim/user
     * Assign an existing private plan_id to a verified Stellar user.
     */
    public function patchUser(Request $request): JsonResponse
    {
        $this->normalizePlanId($request);

        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
            'user_id' => ['required', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'in:'.implode(',', self::USER_LINK_SOURCES)],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();

        try {
            $result = $this->simcardService->assignUserByPlanId(
                planId: $data['plan_id'],
                userId: (int) $data['user_id'],
                source: $data['source'] ?? 'manual_claim',
            );
        } catch (SimcardOwnershipConflictException $exception) {
            return $this->ownershipConflict($exception->getMessage());
        }

        if ($result['status'] === 'not_found') {
            return response()->json([
                'response_code' => 404,
                'response_message' => 'Simcard was not found.',
            ], 404);
        }

        return response()->json([
            'response_code' => 200,
            'response_message' => $result['status'] === 'already_assigned'
                ? 'Simcard is already assigned to this user.'
                : 'Simcard assigned to user.',
            'data' => $result,
        ], 200);
    }

    /** DELETE /api/v1/sim/user */
    public function deleteUser(Request $request): JsonResponse
    {
        $this->normalizePlanId($request);
        $simcardId = trim((string) $request->input('simcard_id', $request->input('id', '')));
        $request->merge([
            'simcard_id' => $simcardId !== '' ? $simcardId : null,
        ]);

        $validator = Validator::make($request->all(), [
            'simcard_id' => ['nullable', 'uuid', 'required_without:plan_id'],
            'plan_id' => ['nullable', 'string', 'regex:/^\d{16}$/', 'required_without:simcard_id'],
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $data = $validator->validated();

        try {
            $result = isset($data['simcard_id']) && $data['simcard_id'] !== ''
                ? $this->simcardService->detachUserById(
                    simcardId: $data['simcard_id'],
                    userId: (int) $data['user_id'],
                )
                : $this->simcardService->detachUserByPlanId(
                    planId: $data['plan_id'],
                    userId: (int) $data['user_id'],
                );
        } catch (SimcardOwnershipConflictException $exception) {
            return $this->ownershipConflict($exception->getMessage());
        }

        if ($result['status'] === 'not_found') {
            return response()->json([
                'response_code' => 404,
                'response_message' => 'Simcard was not found.',
            ], 404);
        }

        return response()->json([
            'response_code' => 200,
            'response_message' => $result['status'] === 'already_detached'
                ? 'Simcard is already detached.'
                : 'Simcard detached from user.',
            'data' => $result,
        ], 200);
    }

    /** DELETE /api/v1/sim/user/all */
    public function deleteAllUser(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $detached = $this->simcardService->detachAllForUserId(
            (int) $validator->validated()['user_id']
        );

        return response()->json([
            'response_code' => 200,
            'response_message' => 'User simcard associations detached.',
            'data' => [
                'detached_count' => $detached,
            ],
        ], 200);
    }

    private function normalizePlanId(Request $request): void
    {
        if (! $request->has('plan_id')) {
            return;
        }

        $request->merge([
            'plan_id' => preg_replace('/\s+/', '', (string) $request->input('plan_id')),
        ]);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'response_code' => 400,
            'errors' => $errors,
        ], 400);
    }

    private function ownershipConflict(string $message): JsonResponse
    {
        return response()->json([
            'response_code' => 409,
            'response_message' => $message,
        ], 409);
    }
}
