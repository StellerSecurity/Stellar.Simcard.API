<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TopupController extends Controller
{
    public function __construct(
        private readonly TopupService $topupService,
    ) {}


    public function token(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'sim_id' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16}$/'],
                'reason' => ['nullable', 'string', 'max:64'],
            ]);

            return response()->json([
                'response_code' => 200,
                'data' => $this->topupService->createToken(
                    simId: (string) $validated['sim_id'],
                    reason: (string) ($validated['reason'] ?? 'app_requested'),
                ),
            ], 200);
        } catch (ValidationException $exception) {
            return response()->json([
                'response_code' => 422,
                'errors' => $exception->errors(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'response_code' => $this->statusCodeFromException($exception),
                'response_message' => $exception->getMessage(),
            ], $this->statusCodeFromException($exception));
        } catch (Throwable) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Top-up token could not be created.',
            ], 500);
        }
    }

    public function resolve(string $token): JsonResponse
    {
        try {
            return response()->json([
                'response_code' => 200,
                'data' => $this->topupService->resolve($token),
            ], 200);
        } catch (RuntimeException $exception) {
            return response()->json([
                'response_code' => $this->statusCodeFromException($exception),
                'response_message' => $exception->getMessage(),
            ], $this->statusCodeFromException($exception));
        } catch (Throwable) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Top-up link could not be resolved.',
            ], 500);
        }
    }

    public function checkout(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'token' => ['required', 'string', 'max:128'],
                'package_code' => ['required', 'string', 'max:128'],
                'plan' => ['nullable', 'array'],
            ]);

            $result = $this->topupService->checkout(
                token: (string) $validated['token'],
                packageCode: (string) $validated['package_code'],
                selectedPlan: $validated['plan'] ?? [],
            );

            $status = (int) ($result['status_code'] ?? 200);
            unset($result['status_code']);

            return response()->json([
                'response_code' => $status,
                'data' => $result,
            ], $status);
        } catch (ValidationException $exception) {
            return response()->json([
                'response_code' => 422,
                'errors' => $exception->errors(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'response_code' => $this->statusCodeFromException($exception),
                'response_message' => $exception->getMessage(),
            ], $this->statusCodeFromException($exception));
        } catch (Throwable) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Top-up checkout could not be created.',
            ], 500);
        }
    }

    public function fulfill(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'topup_session_id' => ['required', 'string', 'max:64'],
                'commerce_order_id' => ['nullable', 'string', 'max:64'],
                'commerce_order_item_id' => ['nullable', 'string', 'max:64'],
                'idempotency_key' => ['nullable', 'string', 'max:128'],
            ]);

            return response()->json([
                'response_code' => 200,
                'data' => $this->topupService->fulfill(
                    topupSessionId: (string) $validated['topup_session_id'],
                    commerceOrderId: $validated['commerce_order_id'] ?? null,
                    commerceOrderItemId: $validated['commerce_order_item_id'] ?? null,
                    idempotencyKey: $validated['idempotency_key'] ?? null,
                ),
            ], 200);
        } catch (ValidationException $exception) {
            return response()->json([
                'response_code' => 422,
                'errors' => $exception->errors(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'response_code' => $this->statusCodeFromException($exception),
                'response_message' => $exception->getMessage(),
            ], $this->statusCodeFromException($exception));
        } catch (Throwable) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Top-up fulfillment could not be completed.',
            ], 500);
        }
    }

    private function statusCodeFromException(RuntimeException $exception): int
    {
        $code = (int) $exception->getCode();

        return $code >= 400 && $code <= 599 ? $code : 400;
    }
}
