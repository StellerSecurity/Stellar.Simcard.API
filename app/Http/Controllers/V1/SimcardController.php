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
            'plan_id'     => ['required', 'string', 'regex:/^\d{16}$/'],
            'packageCode' => ['required', 'string', 'max:64'],
        ]);

        $result = $this->simcardService->orderAndGetInstallInfo(
            userId: 1,
            accountRef: null,
            packageCode: $data['packageCode'],
            planId: $data['plan_id'],
        );

        return response()->json([
            'response_code' => 201,
            'data' => [
                'simcard' => [
                    'state'        => $result['simcard']->state,
                    'provider'     => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                ],
                'install' => $result['install'],
            ],
        ], 201);
    }


    public function query(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
        ]);

        $result = $this->simcardService->queryStatusByPlanId($data['plan_id']);

        if($result === null) {
            return response()->json(['response_code' => 400]);
        }

        return response()->json([
            'response_code' => 200,
            'data' => [
                'simcard' => [
                    'state'        => $result['simcard']->state,
                    'provider'     => $result['simcard']->provider,
                    'package_code' => $result['simcard']->package_code,
                ],
                'provider' => $result['provider'],
            ],
        ]);
    }
}
