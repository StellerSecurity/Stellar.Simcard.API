<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\SimcardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SimcardController extends Controller
{
    public function __construct(
        private readonly SimcardService $simcardService,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $filters = $request->only(['locationCode','type','packageCode','iccid']);
        $plans   = $this->simcardService->listPlans($filters);

        return response()->json([
            'response_code' => 200,
            'data'          => $plans,
        ]);
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id'     => 'required|string',
            'packageCode' => 'required|string',
            'account_ref' => 'nullable|string|max:191',
        ]);

        $user = $request->user();
        if (!$user) {
            throw ValidationException::withMessages([
                'user' => ['User not authenticated.'],
            ]);
        }

        $simcard = $this->simcardService->orderEsim(
            userId: $user->id,
            accountRef: $data['account_ref'] ?? null,
            packageCode: $data['packageCode'],
            planId: $data['plan_id'],
        );

        return response()->json([
            'response_code' => 201,
            'data'          => [
                'state' => $simcard->state,
            ],
        ], 201);
    }

    public function query(Request $request, string $planId): JsonResponse
    {
        $result = $this->simcardService->queryStatusByPlanId($planId);

        return response()->json([
            'response_code' => 200,
            'data'          => [
                'simcard' => [
                    'state'        => $result['simcard']->state,
                    'provider'     => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                ],
                'provider_raw' => app()->isProduction() ? null : $result['provider'],
            ],
        ]);
    }
}
