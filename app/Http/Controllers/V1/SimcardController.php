<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\SimcardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimcardController extends Controller
{
    public function __construct(
        private readonly SimcardService $simcardService,
    ) {}

    public function plans(Request $request): JsonResponse
    {
        $filters = $request->only(['locationCode', 'type', 'packageCode', 'iccid']);
        $plans   = $this->simcardService->listPlans($filters);

        return response()->json([
            'response_code' => 200,
            'data'          => $plans,
        ]);
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id'     => 'required|string|max:64',
            'packageCode' => 'required|string|max:64',
            'account_ref' => 'nullable|string|max:191',
        ]);

        // userId not needed for now (kiosk flow). Keep placeholder.
        $result = $this->simcardService->orderAndGetInstallInfo(
            userId: 1,
            accountRef: $data['account_ref'] ?? null,
            packageCode: $data['packageCode'],
            planId: $data['plan_id'],
        );

        return response()->json([
            'response_code' => 201,
            'data'          => [
                'simcard' => [
                    'state'        => $result['simcard']->state,
                    'provider'     => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                ],
                // Install payload (AC only).
                'install' => $result['install'],
            ],
        ], 201);
    }

    public function query(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => 'required|string',
        ]);

        $result = $this->simcardService->queryStatusByPlanId($data['plan_id']);

        return response()->json([
            'response_code' => 200,
            'data'          => [
                'simcard' => [
                    'state'        => $result['simcard']->state,
                    'provider'     => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                ],
                // Sanitized provider payload (usage/status only).
                'provider' => $result['provider'],
            ],
        ]);
    }
}
