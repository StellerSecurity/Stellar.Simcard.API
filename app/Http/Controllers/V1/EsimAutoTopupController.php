<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\EsimAutoTopupManagementService;
use App\Services\EsimAutoTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EsimAutoTopupController extends Controller
{
    public function __construct(
        private readonly EsimAutoTopupService $autoTopupService,
        private readonly EsimAutoTopupManagementService $managementService,
    ) {}

    public function status(Request $request): JsonResponse
    {
        try {
            $request->merge([
                'plan_id' => $this->normalizePlanId((string) $request->query('plan_id', '')),
            ]);

            $validated = $request->validate([
                'plan_id' => ['required', 'regex:/^\d{16}$/'],
            ]);

            return $this->noStore(response()->json([
                'response_code' => 200,
                'data' => $this->managementService->statusByPlanId((string) $validated['plan_id']),
            ]));
        } catch (ValidationException $exception) {
            return $this->noStore(response()->json([
                'response_code' => 422,
                'response_message' => 'SIM ID is invalid.',
                'errors' => $exception->errors(),
            ], 422));
        } catch (RuntimeException $exception) {
            return $this->noStore($this->runtimeError($exception));
        } catch (Throwable) {
            return $this->noStore(response()->json([
                'response_code' => 500,
                'response_message' => 'Auto Top-Up status could not be loaded.',
            ], 500));
        }
    }

    public function manage(Request $request): JsonResponse
    {
        try {
            $request->merge([
                'plan_id' => $this->normalizePlanId((string) $request->input('plan_id', '')),
            ]);

            $validated = $request->validate([
                'plan_id' => ['required', 'regex:/^\d{16}$/'],
                'enabled' => ['required', 'boolean'],
                'consent' => ['nullable', 'boolean'],
                'consent_source' => ['nullable', 'string', 'max:64'],
                'consent_version' => ['nullable', 'string', 'max:32'],
            ]);

            $enabled = (bool) $validated['enabled'];
            $consent = (bool) ($validated['consent'] ?? false);

            if ($enabled && ! $consent) {
                throw ValidationException::withMessages([
                    'consent' => ['Auto Top-Up consent is required.'],
                ]);
            }

            return $this->noStore(response()->json([
                'response_code' => 200,
                'data' => $this->managementService->manageByPlanId(
                    planId: (string) $validated['plan_id'],
                    enabled: $enabled,
                    consent: $consent,
                    source: (string) ($validated['consent_source'] ?? 'data_website'),
                    version: (string) ($validated['consent_version'] ?? '1'),
                ),
            ]));
        } catch (ValidationException $exception) {
            return $this->noStore(response()->json([
                'response_code' => 422,
                'response_message' => 'Auto Top-Up request is invalid.',
                'errors' => $exception->errors(),
            ], 422));
        } catch (RuntimeException $exception) {
            return $this->noStore($this->runtimeError($exception));
        } catch (Throwable) {
            return $this->noStore(response()->json([
                'response_code' => 500,
                'response_message' => 'Auto Top-Up could not be updated.',
            ], 500));
        }
    }

    public function paymentFailed(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'topup_session_id' => ['required', 'uuid'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ]);

            $this->autoTopupService->markPaymentFailed(
                (string) $validated['topup_session_id'],
                $validated['reason'] ?? null,
            );

            return response()->json([
                'response_code' => 200,
                'data' => ['status' => 'recorded'],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'response_code' => 422,
                'errors' => $exception->errors(),
            ], 422);
        } catch (RuntimeException $exception) {
            return $this->runtimeError($exception);
        } catch (Throwable) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Auto Top-Up payment failure could not be recorded.',
            ], 500);
        }
    }

    private function normalizePlanId(string $value): string
    {
        return preg_replace('/\s+/', '', trim($value)) ?? '';
    }

    private function runtimeError(RuntimeException $exception): JsonResponse
    {
        $status = (int) $exception->getCode();
        $status = $status >= 400 && $status <= 599 ? $status : 400;

        return response()->json([
            'response_code' => $status,
            'response_message' => $exception->getMessage(),
        ], $status);
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
