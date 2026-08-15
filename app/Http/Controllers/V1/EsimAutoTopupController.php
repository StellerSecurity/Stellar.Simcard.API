<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
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
    ) {}

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
            $status = (int) $exception->getCode();
            $status = $status >= 400 && $status <= 599 ? $status : 400;

            return response()->json([
                'response_code' => $status,
                'response_message' => $exception->getMessage(),
            ], $status);
        } catch (Throwable) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Auto Top-Up payment failure could not be recorded.',
            ], 500);
        }
    }
}
