<?php

namespace App\Http\Controllers\V1\Support;

use App\Http\Controllers\Controller;
use App\Services\Support\EsimSupportReplacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class SimSupportController extends Controller
{
    public function __construct(private readonly EsimSupportReplacementService $support) {}

    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'max:64'],
            'customer_email' => ['required', 'email', 'max:254'],
        ]);

        try {
            $result = $this->support->inspect((string) $data['plan_id'], (string) $data['customer_email']);
            if ($result === null) {
                return response()->json(['response_code' => 404, 'response_message' => 'eSIM not found.'], 404);
            }
            return response()->json(['response_code' => 200, 'data' => $result]);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['response_code' => 500, 'response_message' => 'Support eSIM inspection failed.'], 500);
        }
    }

    public function replaceUnused(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'max:64'],
            'customer_email' => ['required', 'email', 'max:254'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);

        try {
            $result = $this->support->replaceUnused(
                (string) $data['plan_id'],
                (string) $data['customer_email'],
                (string) $data['idempotency_key'],
            );
            return response()->json(['response_code' => 200, 'data' => $result]);
        } catch (RuntimeException $e) {
            return $this->runtimeError($e);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['response_code' => 500, 'response_message' => 'Support eSIM replacement failed.'], 500);
        }
    }

    private function runtimeError(RuntimeException $e): JsonResponse
    {
        $status = (int) $e->getCode();
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        return response()->json(['response_code' => $status, 'response_message' => $e->getMessage()], $status);
    }
}
