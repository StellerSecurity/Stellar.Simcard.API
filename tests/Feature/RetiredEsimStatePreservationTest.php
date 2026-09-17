<?php

namespace Tests\Feature;

use App\Models\Simcard;
use App\Services\Esim\EsimaccessWebhookService;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\EsimAutoTopupService;
use App\Services\EsimDataUsageAlertService;
use App\Services\SimcardActionLinkService;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class RetiredEsimStatePreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'esim.crypto.hash_key' => 'retired-esim-test-hash-key',
            'esim.crypto.master_key' => 'retired-esim-test-master-key',
            'esim-marketing.refund_offer.enabled' => true,
        ]);
    }

    public function test_marketing_usage_detection_rechecks_retirement_under_the_row_lock(): void
    {
        $simcard = $this->simcard('pending');
        Simcard::query()->whereKey($simcard->id)->update(['state' => 'cancelled']);

        $result = (new EsimMarketingRefundOfferService(app(EsimCryptoService::class)))
            ->handleUsageDetected($simcard);

        self::assertSame(['status' => 'locally_retired'], $result);
        self::assertSame('cancelled', $simcard->fresh()->state);
        self::assertNull($simcard->fresh()->activated_at);
        self::assertNull($simcard->fresh()->first_used_at);
        self::assertNull($simcard->fresh()->marketing_refund_notification_attempted_at);
    }

    public function test_marketing_usage_detection_still_activates_an_eligible_simcard(): void
    {
        $simcard = $this->simcard('pending');

        $result = (new EsimMarketingRefundOfferService(app(EsimCryptoService::class)))
            ->handleUsageDetected($simcard);

        self::assertSame(['status' => 'missing_email'], $result);
        self::assertSame('active', $simcard->fresh()->state);
        self::assertNotNull($simcard->fresh()->first_used_at);
    }

    public function test_provider_activation_webhook_preserves_retirement_and_skips_customer_notices(): void
    {
        $simcard = $this->simcard('cancelled');
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldNotReceive('sendSms');
        $marketing = Mockery::mock(EsimMarketingRefundOfferService::class);
        $marketing->shouldNotReceive('handleUsageDetected');
        $quota = Mockery::mock(VirtualEsimQuotaService::class);
        $quota->shouldReceive('processDurationStored')->once()->with((string) $simcard->id)
            ->andReturn(['status' => 'locally_retired']);
        $service = new EsimaccessWebhookService(
            app(EsimCryptoService::class),
            $provider,
            Mockery::mock(SimcardActionLinkService::class),
            $marketing,
            Mockery::mock(EsimAutoTopupService::class),
            $quota,
        );

        $result = $service->handle([
            'notifyType' => 'ESIM_STATUS',
            'content' => [
                'orderNo' => 'retired-order',
                'iccid' => '8945000000000000099',
                'esimStatus' => 'IN_USE',
                'smdpStatus' => 'NOT_SUPPORTED',
            ],
        ]);

        self::assertSame('processed', $result['status']);
        self::assertSame(['status' => 'skipped', 'reason' => 'locally_retired'], $result['sms']);
        self::assertSame(['status' => 'skipped', 'reason' => 'locally_retired'], $result['email']);
        self::assertSame('cancelled', $simcard->fresh()->state);
        self::assertSame('IN_USE', $simcard->fresh()->esim_status);
        self::assertNull($simcard->fresh()->activated_at);
    }

    public function test_usage_alert_polling_skips_locally_retired_profiles_still_in_use_at_provider(): void
    {
        $simcard = $this->simcard('cancelled');
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldNotReceive('queryEsim');
        $provider->shouldNotReceive('sendSms');

        $result = $this->usageAlerts($provider)->processPending(1, (string) $simcard->id, true);

        self::assertSame(0, $result['processed']);
        self::assertSame(0, $result['refreshed']);
        self::assertSame(0, $result['sms_sent']);
        self::assertSame(0, $result['email_sent']);
        self::assertSame('cancelled', $simcard->fresh()->state);
    }

    public function test_usage_alert_refresh_cannot_reactivate_a_simcard_retired_during_provider_query(): void
    {
        $simcard = $this->simcard('pending');
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldNotReceive('sendSms');
        $provider->shouldReceive('queryEsim')->once()
            ->with(null, '8945000000000000099', 'primary')
            ->andReturnUsing(function () use ($simcard): array {
                Simcard::query()->whereKey($simcard->id)->update([
                    'state' => 'cancelled',
                    'remaining_volume' => 0,
                ]);

                return ['obj' => ['esimList' => [[
                    'iccid' => '8945000000000000099',
                    'esimStatus' => 'IN_USE',
                    'smdpStatus' => 'NOT_SUPPORTED',
                    'totalVolume' => 1000,
                    'orderUsage' => 900,
                    'remain' => 100,
                ]]]];
            });

        $result = $this->usageAlerts($provider)->processPending(1, (string) $simcard->id, true);

        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['refreshed']);
        self::assertSame(0, $result['triggered']);
        self::assertSame('cancelled', $simcard->fresh()->state);
        self::assertSame(0, (int) $simcard->fresh()->remaining_volume);
        self::assertNull($simcard->fresh()->activated_at);
    }

    private function usageAlerts(EsimProvider $provider): EsimDataUsageAlertService
    {
        return new EsimDataUsageAlertService(
            app(EsimCryptoService::class),
            $provider,
            Mockery::mock(SimcardActionLinkService::class),
            Mockery::mock(VirtualEsimQuotaService::class),
        );
    }

    private function simcard(string $state): Simcard
    {
        $crypto = app(EsimCryptoService::class);

        return Simcard::query()->create([
            'plan_id_hash' => $crypto->derivePlanHash('1111222233334444'),
            'provider' => 'esimaccess',
            'provider_account' => 'primary',
            'package_code' => 'CA-75GB-20D',
            'external_order_id_enc' => '',
            'external_order_id_hash' => $crypto->deriveExternalOrderHash('retired-order'),
            'iccid_enc' => $crypto->encryptSensitiveValue('8945000000000000099'),
            'state' => $state,
            'esim_status' => 'IN_USE',
            'smdp_status' => 'NOT_SUPPORTED',
            'order_usage' => 0,
            'remaining_volume' => 0,
        ]);
    }
}
