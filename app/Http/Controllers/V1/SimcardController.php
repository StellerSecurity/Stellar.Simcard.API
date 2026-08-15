<?php

namespace App\Http\Controllers\V1;

use App\Exceptions\SimcardOwnershipConflictException;
use App\Http\Controllers\Controller;
use App\Services\SimcardService;
use App\Services\EsimAutoTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $filters = $request->only(['locationCode', 'type', 'packageCode', 'iccid']);
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
            );
        } catch (SimcardOwnershipConflictException $exception) {
            return $this->ownershipConflict($exception->getMessage());
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
                    // Boolean only: confirms ownership was linked without
                    // exposing the raw user ID or privacy-preserving user_ref.
                    'account_linked' => $result['simcard']->user_ref !== null,
                    'auto_topup_configured' => $autoTopupConfigured,
                ],
                'install' => $result['install'],
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
