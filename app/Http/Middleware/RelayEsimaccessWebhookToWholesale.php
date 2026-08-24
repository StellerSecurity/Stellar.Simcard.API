<?php

namespace App\Http\Middleware;

use App\Services\Esim\WholesaleWebhookRelayService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RelayEsimaccessWebhookToWholesale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pure pass-through. Existing eSIMAccess webhook processing executes
        // exactly as before, including SMS/email/Auto Top-Up behavior.
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            app(WholesaleWebhookRelayService::class)->capture($request);
        } catch (Throwable $exception) {
            // This runs after the original webhook response. It only writes an
            // isolated outbox row; network delivery happens later via scheduler.
            // Failure must never change, retry, or suppress the customer flow.
            Log::warning('Wholesale webhook tap failed after response.', [
                'exception' => class_basename($exception),
            ]);
        }
    }
}
