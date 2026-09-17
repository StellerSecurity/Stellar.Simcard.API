<?php

namespace Tests\Feature;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Models\SimcardAutoTopupAttempt;
use App\Models\SimcardTopupSession;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\Esim\SimcardUserReferenceService;
use App\Services\EsimAutoTopupManagementService;
use App\Services\EsimAutoTopupPaymentRecoveryService;
use App\Services\EsimAutoTopupService;
use App\Services\SimcardActionLinkService;
use App\Services\SimcardService;
use App\Services\TopupService;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class RetiredEsimTopupSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function retiredSim(array $attributes = []): Simcard
    {
        return Simcard::create(array_merge([
            'plan_id_hash' => hash('sha256', (string) Str::uuid()),
            'provider' => 'esimaccess',
            'provider_account' => 'primary',
            'package_code' => 'ORIGINAL',
            'external_order_id_enc' => 'encrypted-order',
            'iccid_enc' => 'encrypted-iccid',
            'state' => 'cancelled',
            'esim_status' => 'IN_USE',
            'total_volume' => 1000,
            'remaining_volume' => 0,
            'order_usage' => 0,
            'commerce_order_id' => (string) Str::uuid(),
            'commerce_order_item_id' => (string) Str::uuid(),
            'commerce_unit' => 1,
        ], $attributes));
    }

    private function topups(?EsimCryptoService $crypto = null): TopupService
    {
        return new TopupService(
            $crypto ?? Mockery::mock(EsimCryptoService::class),
            Mockery::mock(EsimProvider::class),
            Mockery::mock(SimcardActionLinkService::class),
            Mockery::mock(VirtualEsimQuotaService::class),
        );
    }

    private function autoTopups(): EsimAutoTopupService
    {
        return new EsimAutoTopupService(
            Mockery::mock(TopupService::class),
            Mockery::mock(EsimCryptoService::class),
            Mockery::mock(EsimProvider::class),
            Mockery::mock(VirtualEsimQuotaService::class),
            Mockery::mock(EsimAutoTopupPaymentRecoveryService::class),
        );
    }

    private function topupSession(Simcard $sim, array $attributes = []): SimcardTopupSession
    {
        return SimcardTopupSession::create(array_merge([
            'simcard_id' => $sim->id,
            'package_code' => 'TOPUP',
            'idempotency_key' => (string) Str::uuid(),
            'status' => 'PAID',
            'paid_at' => now(),
            'commerce_order_id' => (string) Str::uuid(),
            'meta' => ['payment_reference' => 'test-payment'],
        ], $attributes));
    }

    private function autoConfig(Simcard $sim, array $attributes = []): SimcardAutoTopup
    {
        return SimcardAutoTopup::create(array_merge([
            'simcard_id' => $sim->id,
            'parent_commerce_order_id' => $sim->commerce_order_id,
            'parent_commerce_order_item_id' => $sim->commerce_order_item_id,
            'commerce_unit' => 1,
            'enabled' => true,
            'state' => 'ARMED',
            'preferred_data_bytes' => 1000,
            'cycle' => 1,
        ], $attributes));
    }

    private function assertRetirementBlocks(callable $call): void
    {
        try {
            $call();
            $this->fail('Retirement must block the provider or payment operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame(409, $exception->getCode());
            $this->assertStringContainsString('cancelled or replaced', $exception->getMessage());
        }
    }

    public function test_provider_in_use_does_not_allow_new_manual_auto_or_included_topups(): void
    {
        $sim = $this->retiredSim();
        $crypto = Mockery::mock(EsimCryptoService::class);
        $crypto->shouldReceive('derivePlanHash')->once()->with('1234567890123456')->andReturn($sim->plan_id_hash);
        $topups = $this->topups($crypto);

        $this->assertRetirementBlocks(fn () => $topups->createToken('1234567890123456'));
        $this->assertRetirementBlocks(fn () => $topups->prepareAutoTopupSession($sim, 1000, 7, (string) Str::uuid()));
        $this->assertRetirementBlocks(fn () => $topups->prepareIncludedVirtualTopupSession($sim, 'TOPUP', (string) Str::uuid()));
        $this->assertSame(0, SimcardTopupSession::query()->count());
    }

    public function test_paid_callback_cannot_reactivate_or_topup_a_retired_profile_and_keeps_payment_evidence(): void
    {
        $sim = $this->retiredSim();
        $session = $this->topupSession($sim);
        $before = $session->fresh()->getAttributes();

        $this->assertRetirementBlocks(fn () => $this->topups()->fulfill($session->id));
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertSame('cancelled', $sim->fresh()->state);
    }

    public function test_completed_paid_topup_still_returns_its_idempotent_result_after_retirement(): void
    {
        $session = $this->topupSession($this->retiredSim(), [
            'status' => 'FULFILLED',
            'supplier_reference' => 'already-delivered',
            'fulfilled_at' => now(),
        ]);

        $result = $this->topups()->fulfill($session->id);

        $this->assertTrue($result['idempotent']);
        $this->assertSame('FULFILLED', $result['status']);
        $this->assertSame('already-delivered', $result['supplier_reference']);
    }

    public function test_stale_virtual_restore_request_rechecks_retirement_and_never_unsuspends_provider(): void
    {
        $sim = $this->retiredSim([
            'state' => 'suspended',
            'esim_status' => 'SUSPENDED',
            'virtual_fulfillment_recipe' => [
                'strategy' => VirtualEsimQuotaService::STRATEGY,
                'target_data_bytes' => 1000,
                'quota' => ['entitlement_bytes' => 1000, 'state' => 'SUSPENDED'],
            ],
        ]);
        $stale = $sim->fresh();
        $sim->update(['state' => 'cancelled', 'esim_status' => 'IN_USE']);
        $service = new VirtualEsimQuotaService(Mockery::mock(EsimProvider::class), Mockery::mock(EsimCryptoService::class));

        $this->assertFalse($service->allowsPaidTopupWhileSuspended($sim->fresh()));
        $this->assertRetirementBlocks(fn () => $service->restoreForPaidTopup($stale));
        $this->assertSame('skipped', $service->enforceSuspend($sim->id)['status']);
        $this->assertSame('cancelled', $sim->fresh()->state);
    }

    public function test_retirement_blocks_auto_cycle_claiming_even_if_configuration_was_still_enabled(): void
    {
        $sim = $this->retiredSim();
        $config = $this->autoConfig($sim);
        $service = $this->autoTopups();

        $this->assertSame('esim_locally_retired', $service->processUsage($sim)['reason']);
        $claim = new ReflectionMethod($service, 'claimCycle');
        $this->assertSame([null, null], $claim->invoke($service, $config->id));
        $this->assertSame(0, SimcardAutoTopupAttempt::query()->count());
    }

    public function test_retirement_cancels_unstarted_auto_payment_but_preserves_inflight_payment_evidence(): void
    {
        foreach ([false, true] as $paymentStarted) {
            $sim = $this->retiredSim();
            $config = $this->autoConfig($sim, ['state' => 'PROCESSING']);
            $session = $this->topupSession($sim, ['status' => 'PENDING_PAYMENT', 'commerce_order_id' => null, 'paid_at' => null]);
            $attempt = SimcardAutoTopupAttempt::create([
                'auto_topup_id' => $config->id,
                'cycle' => 1,
                'attempt_key' => (string) Str::uuid(),
                'status' => 'EXECUTING',
                'topup_session_id' => $session->id,
                'stripe_payment_intent_id' => $paymentStarted ? 'pi_existing_test' : null,
                'meta' => $paymentStarted ? ['commerce_request_started_at' => now()->toIso8601String()] : [],
            ]);
            $begin = new ReflectionMethod(EsimAutoTopupService::class, 'beginCommerceRequest');

            $this->assertFalse($begin->invoke($this->autoTopups(), $config->id, $attempt->id));
            $this->assertFalse($config->fresh()->enabled);
            if ($paymentStarted) {
                $this->assertSame('EXECUTING', $attempt->fresh()->status);
                $this->assertSame('pi_existing_test', $attempt->fresh()->stripe_payment_intent_id);
                $this->assertTrue($attempt->fresh()->meta['retired_esim_payment_reconciliation_required']);
                $this->assertSame('PENDING_PAYMENT', $session->fresh()->status);
            } else {
                $this->assertSame('FAILED', $attempt->fresh()->status);
                $this->assertSame('CANCELLED', $session->fresh()->status);
            }
        }
    }

    public function test_customer_disabled_inflight_payment_can_still_reconcile_for_a_nonretired_sim(): void
    {
        $sim = $this->retiredSim(['state' => 'active']);
        $config = $this->autoConfig($sim, ['enabled' => false, 'state' => 'PROCESSING']);
        $attempt = SimcardAutoTopupAttempt::create([
            'auto_topup_id' => $config->id,
            'cycle' => 1,
            'attempt_key' => (string) Str::uuid(),
            'status' => 'EXECUTING',
            'meta' => ['commerce_request_started_at' => now()->toIso8601String()],
        ]);
        $begin = new ReflectionMethod(EsimAutoTopupService::class, 'beginCommerceRequest');

        $this->assertTrue($begin->invoke($this->autoTopups(), $config->id, $attempt->id));
        $this->assertSame('EXECUTING', $attempt->fresh()->status);
        $this->assertFalse($config->fresh()->enabled);
    }

    public function test_auto_topup_cannot_be_reenabled_or_recreated_from_commerce_for_a_retired_sim(): void
    {
        Http::preventStrayRequests();
        $sim = $this->retiredSim();
        $crypto = Mockery::mock(EsimCryptoService::class);
        $crypto->shouldReceive('derivePlanHash')->twice()->with('1234567890123456')->andReturn($sim->plan_id_hash);
        $management = new EsimAutoTopupManagementService($crypto);

        $this->assertRetirementBlocks(fn () => $management->manageByPlanId('1234567890123456', true, true));
        $status = $management->statusByPlanId('1234567890123456');
        $this->assertFalse($status['can_enable']);
        $this->assertSame('esim_locally_retired', $status['reason_code']);
        $this->assertSame(0, SimcardAutoTopup::query()->count());
        Http::assertNothingSent();
    }

    public function test_provider_refresh_that_finishes_after_retirement_cannot_restore_local_state(): void
    {
        foreach (['auto', 'quota'] as $kind) {
            $sim = $this->retiredSim([
                'state' => 'active',
                'virtual_fulfillment_recipe' => [
                    'strategy' => VirtualEsimQuotaService::STRATEGY,
                    'target_data_bytes' => 1000,
                    'quota' => ['entitlement_bytes' => 1000, 'state' => 'MONITORING'],
                ],
            ]);
            $crypto = Mockery::mock(EsimCryptoService::class);
            $crypto->shouldReceive('decryptSensitiveValue')->once()->with('encrypted-iccid')->andReturn('8945000000000000000');
            $provider = Mockery::mock(EsimProvider::class);
            if ($kind === 'auto') {
                $provider->shouldReceive('resolveAccountForEsim')->once()->andReturn('primary');
            }
            $provider->shouldReceive('queryEsim')->once()->andReturnUsing(function () use ($sim): array {
                Simcard::query()->whereKey($sim->id)->update(['state' => 'cancelled', 'remaining_volume' => 0]);

                return ['obj' => ['esimList' => [[
                    'esimStatus' => 'IN_USE', 'orderUsage' => 0, 'totalVolume' => 1000,
                ]]]];
            });
            $service = $kind === 'auto'
                ? new EsimAutoTopupService(
                    Mockery::mock(TopupService::class), $crypto, $provider,
                    Mockery::mock(VirtualEsimQuotaService::class),
                    Mockery::mock(EsimAutoTopupPaymentRecoveryService::class),
                )
                : new VirtualEsimQuotaService($provider, $crypto);
            $refresh = new ReflectionMethod($service, 'refreshUsageFromProvider');
            $refresh->invoke($service, $sim);

            $this->assertSame('cancelled', $sim->fresh()->state, $kind);
            $this->assertSame(0, (int) $sim->fresh()->remaining_volume, $kind);
        }
    }

    public function test_status_refresh_preserves_retirement_when_provider_still_reports_in_use(): void
    {
        $sim = $this->retiredSim(['activated_at' => null]);
        $crypto = Mockery::mock(EsimCryptoService::class);
        $crypto->shouldReceive('derivePlanHash')->once()->with('1234567890123456')->andReturn($sim->plan_id_hash);
        $crypto->shouldReceive('decryptForPlan')->once()->with('1234567890123456', 'encrypted-order')->andReturn('provider-order');
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldReceive('queryOrder')->once()->with('provider-order', 'primary')->andReturn([
            'obj' => ['esimList' => [['esimStatus' => 'IN_USE', 'orderUsage' => 0, 'totalVolume' => 1000]]],
        ]);
        $quota = new VirtualEsimQuotaService(Mockery::mock(EsimProvider::class), Mockery::mock(EsimCryptoService::class));
        $service = new SimcardService(
            $provider,
            $crypto,
            Mockery::mock(EsimMarketingRefundOfferService::class),
            new SimcardUserReferenceService(),
            $quota,
        );

        $result = $service->queryStatusByPlanId('1234567890123456');

        $this->assertSame('IN_USE', $result['provider']['esim_status']);
        $this->assertSame('cancelled', $sim->fresh()->state);
        $this->assertNull($sim->fresh()->activated_at);
    }
}
