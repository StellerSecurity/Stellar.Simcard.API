<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\SimcardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
        ], 200);
    }

    /**
     * @throws ValidationException
     */
    public function order(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'plan_id'     => ['required', 'string', 'regex:/^\d{16}$/'],
            'packageCode' => ['required', 'string', 'max:64'],
            'user_id'     => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'response_code' => 400,
                'errors'        => $validator->errors(),
            ], 400);
        }

        $data = $validator->validated();

        $user_id = $data['user_id'] ?? 1;

        $result = $this->simcardService->orderAndGetInstallInfo(
            userId:      $user_id,
            accountRef:  null,
            packageCode: $data['packageCode'],
            planId:      $data['plan_id'],
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
        $validator = Validator::make($request->all(), [
            'plan_id' => ['required', 'string', 'regex:/^\d{16}$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'response_code' => 400,
                'errors'        => $validator->errors(),
            ], 400);
        }

        $data = $validator->validated();

        $result = $this->simcardService->queryStatusByPlanId($data['plan_id']);

        if ($result === null) {
            return response()->json(['response_code' => 400], 400);
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
        ], 200);
    }
}
