<?php

namespace App\Http\Controllers\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Esim\EsimaccessWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EsimaccessWebhookController extends Controller
{
    public function __construct(
        private readonly EsimaccessWebhookService $webhookService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->isHealthCheck($request) && ! $this->hasValidSecret($request)) {
            return response()->json([
                'response_code' => 401,
                'response_message' => 'Unauthorized.',
            ], 401);
        }

        try {
            $result = $this->webhookService->handle($request->all());
        } catch (RuntimeException $exception) {
            return response()->json([
                'response_code' => 400,
                'response_message' => $exception->getMessage(),
            ], 400);
        }

        return response()->json([
            'response_code' => 200,
            'data' => $result,
        ], 200);
    }


    private function isHealthCheck(Request $request): bool
    {
        $notifyType = (string) ($request->input('notifyType') ?? $request->input('notify_type') ?? '');

        return strtoupper(trim($notifyType)) === 'CHECK_HEALTH';
    }

    private function hasValidSecret(Request $request): bool
    {
        $expected = (string) config('services.esimaccess.webhook_secret');

        if ($expected === '') {
            return false;
        }

        $actual = (string) (
            $request->header('X-Stellar-Webhook-Secret')
            ?? $request->header('X-Webhook-Secret')
            ?? ''
        );

        return $actual !== '' && hash_equals($expected, $actual);
    }
}
