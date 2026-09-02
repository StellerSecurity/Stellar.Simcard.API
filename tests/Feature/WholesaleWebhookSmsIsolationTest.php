<?php

namespace Tests\Feature;

use App\Models\Simcard;
use App\Services\Esim\EsimaccessWebhookService;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\EsimAutoTopupService;
use App\Services\SimcardActionLinkService;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WholesaleWebhookSmsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_a_retail_customer_receives_activation_sms(): void
    {
        [$service, $provider, $simcard] = $this->webhookServiceFor('commerce_esim_42');

        $provider->shouldReceive('sendSms')
            ->once()
            ->with(
                '8945000000000000042',
                'Stellar eSIM is now active. Stellar VPN is included for free. Use the login from your order confirmation. Get Stellar VPN here: https://stellarvpn.org/download',
                'primary',
            )
            ->andReturn(['success' => true]);

        $result = $service->handle($this->activationPayload());

        self::assertSame('processed', $result['status']);
        self::assertSame(['status' => 'sent'], $result['sms']);
        self::assertSame('active', $simcard->fresh()->state);
    }

    public function test_variant_b_wholesale_customer_does_not_receive_activation_sms(): void
    {
        [$service, $provider, $simcard] = $this->webhookServiceFor('wholesale_esim_42');

        $provider->shouldNotReceive('sendSms');

        $result = $service->handle($this->activationPayload());

        self::assertSame('processed', $result['status']);
        self::assertSame([
            'status' => 'skipped',
            'reason' => 'wholesale_simcard',
        ], $result['sms']);
        self::assertSame('active', $simcard->fresh()->state);
    }

    /**
     * @return array{EsimaccessWebhookService, EsimProvider, Simcard}
     */
    private function webhookServiceFor(string $idempotencyKey): array
    {
        config()->set('esim.crypto.hash_key', 'test-hash-key');
        config()->set('esim.crypto.master_key', 'test-master-key');

        $crypto = new EsimCryptoService;
        $provider = Mockery::mock(EsimProvider::class);
        $marketingRefundOffer = Mockery::mock(EsimMarketingRefundOfferService::class);
        $marketingRefundOffer->shouldReceive('handleUsageDetected')->once()->andReturn(['status' => 'missing_email']);

        $simcard = Simcard::create([
            'plan_id_hash' => hash('sha256', $idempotencyKey),
            'provider' => 'esimaccess',
            'provider_account' => 'primary',
            'package_code' => 'TEST-PACKAGE',
            'external_order_id_enc' => 'encrypted',
            'external_order_id_hash' => $crypto->deriveExternalOrderHash('provider-order-42'),
            'state' => 'ready',
            'commerce_order_id' => '2d271e8a-3da2-49cb-aa4e-ab0e32529ef3',
            'commerce_order_item_id' => 'e3792992-fbe0-418a-bf75-a265df11f31c',
            'commerce_unit' => 1,
            'idempotency_key' => $idempotencyKey,
        ]);

        $service = new EsimaccessWebhookService(
            $crypto,
            $provider,
            Mockery::mock(SimcardActionLinkService::class),
            $marketingRefundOffer,
            Mockery::mock(EsimAutoTopupService::class),
            Mockery::mock(VirtualEsimQuotaService::class),
        );

        return [$service, $provider, $simcard];
    }

    /**
     * @return array{notifyType: string, content: array<string, string>}
     */
    private function activationPayload(): array
    {
        return [
            'notifyType' => 'ESIM_STATUS',
            'content' => [
                'orderNo' => 'provider-order-42',
                'iccid' => '8945000000000000042',
                'esimStatus' => 'IN_USE',
            ],
        ];
    }
}
