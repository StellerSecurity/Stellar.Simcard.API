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
use StellarSecurity\Notifications\DTO\NotificationEvent;
use StellarSecurity\Notifications\Facades\Notification;
use Tests\TestCase;

class WholesaleWebhookSmsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_a_retail_customer_receives_activation_sms(): void
    {
        [$service, $provider, $simcard, $marketingRefundOffer] = $this->webhookServiceFor('commerce_esim_42');

        $provider->shouldReceive('sendSms')
            ->once()
            ->with(
                '8945000000000000042',
                'Stellar eSIM is now active. Stellar VPN is included for free. Use the login from your order confirmation. Get Stellar VPN here: https://stellarvpn.org/download',
                'primary',
            )
            ->andReturn(['success' => true]);
        $marketingRefundOffer->shouldReceive('handleUsageDetected')->once()->andReturn(['status' => 'missing_email']);

        $result = $service->handle($this->activationPayload());

        self::assertSame('processed', $result['status']);
        self::assertSame(['status' => 'sent'], $result['sms']);
        self::assertSame('active', $simcard->fresh()->state);
    }

    public function test_variant_b_wholesale_customer_does_not_receive_activation_sms(): void
    {
        [$service, $provider, $simcard, $marketingRefundOffer] = $this->webhookServiceFor('wholesale_esim_42');

        $provider->shouldNotReceive('sendSms');
        $marketingRefundOffer->shouldNotReceive('handleUsageDetected');

        $result = $service->handle($this->activationPayload());

        self::assertSame('processed', $result['status']);
        self::assertSame([
            'status' => 'skipped',
            'reason' => 'wholesale_simcard',
        ], $result['sms']);
        self::assertSame('active', $simcard->fresh()->state);
    }

    public function test_variant_a_retail_customer_receives_low_data_email(): void
    {
        [
            $service,
            $provider,
            $simcard,
            $marketingRefundOffer,
            $actionLinks,
            $autoTopupService,
            $virtualQuotaService,
        ] = $this->webhookServiceFor('commerce_esim_42', 'retail@example.com');

        $marketingRefundOffer->shouldReceive('handleUsageDetected')->once()->andReturn(['status' => 'already_queued']);
        $autoTopupService->shouldReceive('processUsage')->once()->with((string) $simcard->id)->andReturn(['status' => 'disabled']);
        $virtualQuotaService->shouldReceive('processStoredUsage')->once()->with((string) $simcard->id)->andReturn(['status' => 'not_capped']);
        $actionLinks->shouldReceive('createTopupUrl')->twice()->andReturn('https://data.example.test/topup');
        $provider->shouldReceive('queryEsim')->twice()->andReturn([]);
        $provider->shouldReceive('sendSms')->once()->andReturn(['success' => true]);
        Notification::shouldReceive('send')
            ->once()
            ->with(Mockery::on(static function (NotificationEvent $event): bool {
                $payload = $event->toArray();

                return ($payload['event_name'] ?? null) === 'esim_low_data'
                    && ($payload['email'] ?? null) === 'retail@example.com';
            }));

        $result = $service->handle($this->dataUsagePayload());

        self::assertSame('processed', $result['status']);
        self::assertSame(['status' => 'sent'], $result['sms']);
        self::assertSame('sent', $result['email']['status']);
        self::assertSame('esim_low_data', $result['email']['event']);
    }

    public function test_variant_b_wholesale_customer_does_not_receive_any_email_even_when_address_is_stored(): void
    {
        [
            $service,
            $provider,
            $simcard,
            $marketingRefundOffer,
            $actionLinks,
            $autoTopupService,
            $virtualQuotaService,
        ] = $this->webhookServiceFor('wholesale_esim_42', 'wholesale@example.com');

        $provider->shouldNotReceive('sendSms');
        $provider->shouldNotReceive('queryEsim');
        $marketingRefundOffer->shouldNotReceive('handleUsageDetected');
        $actionLinks->shouldNotReceive('createTopupUrl');
        $autoTopupService->shouldReceive('processUsage')->once()->with((string) $simcard->id)->andReturn(['status' => 'disabled']);
        $virtualQuotaService->shouldReceive('processStoredUsage')->once()->with((string) $simcard->id)->andReturn(['status' => 'not_capped']);
        Notification::shouldReceive('send')->never();

        $result = $service->handle($this->dataUsagePayload());

        self::assertSame('processed', $result['status']);
        self::assertSame([
            'status' => 'skipped',
            'reason' => 'wholesale_simcard',
        ], $result['sms']);
        self::assertSame([
            'status' => 'skipped',
            'reason' => 'wholesale_simcard',
        ], $result['email']);
    }

    /**
     * @return array{
     *     EsimaccessWebhookService,
     *     EsimProvider,
     *     Simcard,
     *     EsimMarketingRefundOfferService,
     *     SimcardActionLinkService,
     *     EsimAutoTopupService,
     *     VirtualEsimQuotaService
     * }
     */
    private function webhookServiceFor(string $idempotencyKey, ?string $email = null): array
    {
        config()->set('esim.crypto.hash_key', 'test-hash-key');
        config()->set('esim.crypto.master_key', 'test-master-key');

        $crypto = new EsimCryptoService;
        $provider = Mockery::mock(EsimProvider::class);
        $marketingRefundOffer = Mockery::mock(EsimMarketingRefundOfferService::class);
        $actionLinks = Mockery::mock(SimcardActionLinkService::class);
        $autoTopupService = Mockery::mock(EsimAutoTopupService::class);
        $virtualQuotaService = Mockery::mock(VirtualEsimQuotaService::class);

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
            'email_enc' => $email === null ? null : $crypto->encryptEmail($email),
            'email_hash' => $email === null ? null : $crypto->deriveEmailHash($email),
            'email_opt_in_at' => $email === null ? null : now(),
            'email_source' => $email === null ? null : 'test',
        ]);

        $service = new EsimaccessWebhookService(
            $crypto,
            $provider,
            $actionLinks,
            $marketingRefundOffer,
            $autoTopupService,
            $virtualQuotaService,
        );

        return [
            $service,
            $provider,
            $simcard,
            $marketingRefundOffer,
            $actionLinks,
            $autoTopupService,
            $virtualQuotaService,
        ];
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

    /**
     * @return array{notifyType: string, content: array<string, int|string>}
     */
    private function dataUsagePayload(): array
    {
        return [
            'notifyType' => 'DATA_USAGE',
            'content' => [
                'orderNo' => 'provider-order-42',
                'iccid' => '8945000000000000042',
                'totalVolume' => 1_000,
                'orderUsage' => 900,
                'remain' => 100,
            ],
        ];
    }
}
