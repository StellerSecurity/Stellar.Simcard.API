<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\WholesaleSimcardSmsService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class WholesaleSimcardSmsController extends Controller
{
    public function __invoke(Request $request, WholesaleSimcardSmsService $sms): JsonResponse
    {
        $request->merge([
            'plan_id' => preg_replace('/\s+/', '', (string) $request->input('plan_id')),
            'message' => trim((string) $request->input('message')),
        ]);

        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
            'commerce_order_id' => ['required', 'uuid'],
            'commerce_order_item_id' => ['required', 'uuid'],
            'commerce_unit' => ['required', 'integer', 'min:1', 'max:99'],
            'message' => [
                'required',
                'string',
                'max:500',
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+$/u',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'response_code' => 400,
                'errors' => $validator->errors()->toArray(),
            ], 400);
        }

        $data = $validator->validated();

        try {
            $sent = $sms->send(
                planId: (string) $data['plan_id'],
                commerceOrderId: (string) $data['commerce_order_id'],
                commerceOrderItemId: (string) $data['commerce_order_item_id'],
                commerceUnit: (int) $data['commerce_unit'],
                message: (string) $data['message'],
            );
        } catch (DomainException $exception) {
            return response()->json([
                'response_code' => 409,
                'response_message' => $exception->getMessage(),
            ], 409);
        } catch (Throwable $exception) {
            Log::warning('Wholesale eSIM SMS delivery failed.', [
                'commerce_order_id' => (string) $data['commerce_order_id'],
                'commerce_order_item_id' => (string) $data['commerce_order_item_id'],
                'commerce_unit' => (int) $data['commerce_unit'],
                'exception' => class_basename($exception),
            ]);

            return response()->json([
                'response_code' => 503,
                'response_message' => 'SMS delivery is temporarily unavailable.',
            ], 503, ['Retry-After' => '60']);
        }

        if (! $sent) {
            return response()->json([
                'response_code' => 404,
                'response_message' => 'Simcard was not found.',
            ], 404);
        }

        return response()->json([
            'response_code' => 200,
            'data' => ['status' => 'sent'],
        ]);
    }
}
