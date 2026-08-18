<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\UnusedEsimCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UnusedEsimCancelController extends Controller
{
    public function __invoke(Request $request, UnusedEsimCancellationService $cancellations): JsonResponse
    {
        $planId = preg_replace('/\s+/', '', (string) $request->input('plan_id', '')) ?? '';
        $request->merge(['plan_id' => $planId]);

        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'response_code' => 400,
                'errors' => $validator->errors()->toArray(),
            ], 400);
        }

        try {
            $result = $cancellations->cancel($validator->validated()['plan_id']);
        } catch (\DomainException $exception) {
            return response()->json([
                'response_code' => 409,
                'response_message' => $exception->getMessage(),
            ], 409);
        } catch (Throwable $exception) {
            Log::error('Unused eSIM cancellation could not be confirmed.', [
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return response()->json([
                'response_code' => 502,
                'response_message' => 'The provider could not confirm the eSIM cancellation yet.',
            ], 502);
        }

        if ($result === null) {
            return response()->json([
                'response_code' => 404,
                'response_message' => 'Simcard was not found.',
            ], 404);
        }

        return response()->json([
            'response_code' => 200,
            'response_message' => $result['status'] === 'already_cancelled'
                ? 'eSIM is already cancelled.'
                : 'eSIM cancelled.',
            'data' => $result,
        ], 200);
    }
}
