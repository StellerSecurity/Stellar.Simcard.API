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

    private function statusCodeFromException(RuntimeException $exception): int
    {
        $code = (int) $exception->getCode();

        return $code >= 400 && $code <= 599 ? $code : 400;
    }
}
