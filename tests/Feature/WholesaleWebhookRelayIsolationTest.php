<?php

namespace Tests\Feature;

use App\Models\Simcard;
use App\Models\WholesaleWebhookRelay;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\WholesaleWebhookRelayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WholesaleWebhookRelayIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_customer_webhook_is_not_captured_for_wholesale(): void
    {
        config()->set('services.stellar_wholesale.webhook_relay_url', 'https://wholesale.example.test/api/internal/simcard/provider-webhook');
        config()->set('services.stellar_wholesale.webhook_relay_secret', 'shared-secret');

        $crypto = Mockery::mock(EsimCryptoService::class);
        $crypto->shouldReceive('deriveExternalOrderHash')->with('normal-order')->once()->andReturn('normal-order-hash');

        Simcard::create([
            'plan_id_hash' => str_repeat('a', 64),
            'provider' => 'esimaccess',
            'package_code' => 'PKG-NORMAL',
            'external_order_id_enc' => 'encrypted',
            'external_order_id_hash' => 'normal-order-hash',
            'state' => 'ready',
            // Deliberately no commerce correlation: this is a normal customer.
        ]);

        $service = new WholesaleWebhookRelayService($crypto);
        $request = Request::create(
            '/api/v1/webhooks/esimaccess',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['notifyType' => 'DATA_USAGE', 'content' => ['orderNo' => 'normal-order']], JSON_THROW_ON_ERROR),
        );

        self::assertNull($service->capture($request));
        self::assertSame(0, WholesaleWebhookRelay::query()->count());
    }

    public function test_wholesale_webhook_is_captured_with_durable_commerce_context(): void
    {
        config()->set('services.stellar_wholesale.webhook_relay_url', 'https://wholesale.example.test/api/internal/simcard/provider-webhook');
        config()->set('services.stellar_wholesale.webhook_relay_secret', 'shared-secret');

        $crypto = Mockery::mock(EsimCryptoService::class);
        $crypto->shouldReceive('deriveExternalOrderHash')->with('wholesale-order')->once()->andReturn('wholesale-order-hash');

        Simcard::create([
            'plan_id_hash' => str_repeat('b', 64),
            'provider' => 'esimaccess',
            'package_code' => 'PKG-WHOLESALE',
            'external_order_id_enc' => 'encrypted',
            'external_order_id_hash' => 'wholesale-order-hash',
            'state' => 'ready',
            'commerce_order_id' => '2d271e8a-3da2-49cb-aa4e-ab0e32529ef3',
            'commerce_order_item_id' => 'e3792992-fbe0-418a-bf75-a265df11f31c',
            'commerce_unit' => 2,
        ]);

        $service = new WholesaleWebhookRelayService($crypto);
        $request = Request::create(
            '/api/v1/webhooks/esimaccess',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['notifyType' => 'ESIM_STATUS', 'content' => ['orderNo' => 'wholesale-order']], JSON_THROW_ON_ERROR),
        );

        $relay = $service->capture($request);

        self::assertNotNull($relay);
        self::assertSame('2d271e8a-3da2-49cb-aa4e-ab0e32529ef3', $relay->commerce_order_id);
        self::assertSame('e3792992-fbe0-418a-bf75-a265df11f31c', $relay->commerce_order_item_id);
        self::assertSame(2, $relay->commerce_unit);
        self::assertSame('pending', $relay->status);
    }

    public function test_stale_delivering_relay_is_recovered_and_sent(): void
    {
        config()->set('services.stellar_wholesale.webhook_relay_url', 'https://wholesale.example.test/api/internal/simcard/provider-webhook');
        config()->set('services.stellar_wholesale.webhook_relay_secret', 'shared-secret');
        config()->set('services.stellar_wholesale.webhook_relay_stale_seconds', 120);

        Http::fake([
            'https://wholesale.example.test/*' => Http::response(['data' => ['status' => 'accepted']], 202),
        ]);

        $body = json_encode(['notifyType' => 'DATA_USAGE', 'content' => ['orderNo' => 'wholesale-order']], JSON_THROW_ON_ERROR);
        $relay = WholesaleWebhookRelay::create([
            'id' => '33b62983-7051-4018-b739-acbf99259a2b',
            'provider' => 'esimaccess',
            'payload_encrypted' => Crypt::encryptString($body),
            'content_type' => 'application/json',
            'commerce_order_id' => '2d271e8a-3da2-49cb-aa4e-ab0e32529ef3',
            'commerce_order_item_id' => 'e3792992-fbe0-418a-bf75-a265df11f31c',
            'commerce_unit' => 1,
            'status' => 'delivering',
            'attempts' => 1,
            'received_at' => now()->subMinutes(10),
            'last_attempt_at' => now()->subMinutes(5),
            'next_attempt_at' => null,
        ]);

        $crypto = Mockery::mock(EsimCryptoService::class);
        $service = new WholesaleWebhookRelayService($crypto);
        $summary = $service->retryPending(10);

        self::assertSame(1, $summary['processed']);
        self::assertSame(1, $summary['delivered']);
        self::assertSame('delivered', $relay->fresh()->status);
        self::assertSame(2, $relay->fresh()->attempts);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://wholesale.example.test/api/internal/simcard/provider-webhook'
                && $request->hasHeader('X-Stellar-Commerce-Order-Id', '2d271e8a-3da2-49cb-aa4e-ab0e32529ef3')
                && $request->hasHeader('X-Stellar-Commerce-Order-Item-Id', 'e3792992-fbe0-418a-bf75-a265df11f31c')
                && $request->hasHeader('X-Stellar-Commerce-Unit', '1')
                && $request->hasHeader('X-Stellar-Relay-Signature');
        });
    }
}
