<?php

namespace Tests\Feature;

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use App\Services\UnusedEsimCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReplacementFallbackSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN_ID = '1234567890123456';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'esim.crypto.hash_key' => 'fallback-test-hash-key',
            'esim.crypto.master_key' => 'fallback-test-master-key',
        ]);
    }

    private function simcard(): Simcard
    {
        $crypto = app(EsimCryptoService::class);

        return Simcard::query()->create([
            'plan_id_hash' => $crypto->derivePlanHash(self::PLAN_ID),
            'provider' => 'esimaccess',
            'provider_account' => 'primary',
            'package_code' => 'TEST-PLAN',
            'external_order_id_enc' => $crypto->encryptForPlan(self::PLAN_ID, 'TEST-ORDER'),
            'state' => 'active',
            'esim_status' => 'IN_USE',
            'smdp_status' => 'ENABLED',
            'order_usage' => 0,
            'remaining_volume' => 1024,
        ]);
    }

    private static function profile(mixed $usage = 0, string $esimStatus = 'IN_USE', string $smdpStatus = 'NOT_SUPPORTED'): array
    {
        return ['success' => true, 'obj' => ['esimList' => [[
            'esimTranNo' => 'TEST-TRANSACTION',
            'esimStatus' => $esimStatus,
            'smdpStatus' => $smdpStatus,
            'orderUsage' => $usage,
        ]]]];
    }

    private function service(EsimProvider $provider): UnusedEsimCancellationService
    {
        return new UnusedEsimCancellationService($provider, app(EsimCryptoService::class));
    }

    public function test_replacement_and_retry_reuse_one_new_profile_after_local_supersession(): void
    {
        $old = $this->simcard();
        $crypto = app(EsimCryptoService::class);
        $email = 'replacement@example.test';
        $old->forceFill(['email_hash' => $crypto->deriveEmailHash($email)])->save();
        $new = Simcard::query()->create([
            'plan_id_hash' => $crypto->derivePlanHash('6543210987654321'),
            'external_order_id_enc' => $crypto->encryptForPlan('6543210987654321', 'NEW-TEST-ORDER'),
            'provider' => 'esimaccess',
            'package_code' => 'TEST-PLAN',
            'state' => 'OK',
        ]);
        $install = ['qr_code_url' => 'https://example.test/installation'];
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldReceive('queryOrder')->times(3)->andReturn(self::profile());
        $provider->shouldReceive('revokeEsim')->once()->andReturn(['errorCode' => '200002']);
        $provider->shouldReceive('suspendEsimByTransaction')->once()->andReturn(['errorCode' => '200010']);
        $simcards = Mockery::mock(\App\Services\SimcardService::class);
        $simcards->shouldReceive('findByPlanId')->andReturnUsing(
            fn (string $planId) => $planId === self::PLAN_ID ? $old : null
        );
        $simcards->shouldReceive('queryStatusByPlanId')->once()->with(self::PLAN_ID)
            ->andReturn(['provider' => ['used_bytes' => 0]]);
        $simcards->shouldReceive('queryStatusByPlanId')->twice()
            ->with(Mockery::on(fn ($id) => $id !== self::PLAN_ID))
            ->andReturn(['provider' => ['used_bytes' => 0], 'install' => $install]);
        $simcards->shouldReceive('orderAndGetInstallInfo')->once()
            ->andReturnUsing(function (...$arguments) use ($old, $new, $install): array {
                self::assertSame('cancelled', $old->fresh()->state);
                self::assertSame('TEST-PLAN', $arguments[2]);
                return ['simcard' => $new, 'install' => $install];
            });
        $service = new \App\Services\Support\EsimSupportReplacementService(
            $simcards,
            $this->service($provider),
            Mockery::mock(\App\Services\VirtualEsimFulfillmentService::class),
            $crypto,
        );

        $first = $service->replaceUnused(self::PLAN_ID, $email, 'test-replacement-action');
        $retry = $service->replaceUnused(self::PLAN_ID, $email, 'test-replacement-action');

        self::assertTrue($first['success']);
        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($retry['idempotent_replay']);
        self::assertSame($first['sim_id'], $retry['sim_id']);
        self::assertSame($install, $retry['install']);
        self::assertSame(1, \App\Models\SimcardSupportReplacement::query()->count());
        $this->assertDatabaseHas('simcard_support_replacements', [
            'old_simcard_id' => $old->id,
            'new_simcard_id' => $new->id,
            'status' => 'completed',
        ]);
    }

    #[DataProvider('rejections')]
    public function test_structured_suspend_rejections_require_fresh_exact_zero_usage(array $rejection): void
    {
        $old = $this->simcard();
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldReceive('queryOrder')->times(3)->with('TEST-ORDER', 'primary')->andReturn(self::profile());
        $provider->shouldReceive('revokeEsim')->once()->with('TEST-TRANSACTION', 'primary')->andReturn(['errorCode' => '200002']);
        $provider->shouldReceive('suspendEsimByTransaction')->once()->with('TEST-TRANSACTION', 'primary')->andReturn($rejection);
        $provider->shouldReceive('cancelEsim')->never();

        $result = $this->service($provider)->retireForReplacement(self::PLAN_ID);

        self::assertSame('superseded', $result['status']);
        self::assertFalse($result['provider_retired']);
        self::assertSame('cancelled', $old->fresh()->state);
        self::assertSame('IN_USE', $old->fresh()->esim_status);
        self::assertSame(0, (int) $old->fresh()->remaining_volume);
    }

    public static function rejections(): array
    {
        return [
            'original lifecycle code' => [['errorCode' => '200002']],
            'different explicit code' => [['success' => false, 'errorCode' => '200010']],
            'numeric code' => [['errorCode' => 200009]],
            'explicit rejection without code' => [['success' => false]],
        ];
    }

    #[DataProvider('unsafeProfiles')]
    public function test_final_unverified_usage_or_lifecycle_never_supersedes(mixed $usage, string $esimStatus, string $smdpStatus): void
    {
        $old = $this->simcard();
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldReceive('queryOrder')->times(3)->andReturn(self::profile(), self::profile(), self::profile($usage, $esimStatus, $smdpStatus));
        $provider->shouldReceive('revokeEsim')->once()->andReturn(['errorCode' => '200002']);
        $provider->shouldReceive('suspendEsimByTransaction')->once()->andReturn(['errorCode' => '200010']);
        $provider->shouldReceive('cancelEsim')->never();

        try {
            $this->service($provider)->retireForReplacement(self::PLAN_ID);
            self::fail('Unsafe provider state must not permit supersession.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Provider error code: 200010', $e->getMessage());
        }
        self::assertSame('active', $old->fresh()->state);
    }

    public static function unsafeProfiles(): array
    {
        return [
            'unknown usage' => [null, 'IN_USE', 'NOT_SUPPORTED'],
            'positive usage' => [1, 'IN_USE', 'NOT_SUPPORTED'],
            'negative usage' => [-1, 'IN_USE', 'NOT_SUPPORTED'],
            'fractional usage' => [0.5, 'IN_USE', 'NOT_SUPPORTED'],
            'fractional string usage' => ['0.5', 'IN_USE', 'NOT_SUPPORTED'],
            'boolean usage' => [false, 'IN_USE', 'NOT_SUPPORTED'],
            'missing lifecycle' => [0, 'IN_USE', ''],
            'enabled profile' => [0, 'IN_USE', 'ENABLED'],
            'prepared profile' => [0, 'GOT_RESOURCE', 'NOT_SUPPORTED'],
            'suspended profile' => [0, 'SUSPENDED', 'NOT_SUPPORTED'],
            'exhausted profile' => [0, 'USED_UP', 'NOT_SUPPORTED'],
        ];
    }

    public function test_timeout_during_suspend_never_supersedes(): void
    {
        $old = $this->simcard();
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldReceive('queryOrder')->twice()->andReturn(self::profile());
        $provider->shouldReceive('revokeEsim')->once()->andReturn(['errorCode' => '200002']);
        $provider->shouldReceive('suspendEsimByTransaction')->once()->andThrow(new ConnectionException('Provider timeout'));

        try {
            $this->service($provider)->retireForReplacement(self::PLAN_ID);
            self::fail('A timeout is not a provider rejection.');
        } catch (ConnectionException $e) {
            self::assertSame('Provider timeout', $e->getMessage());
        }
        self::assertSame('active', $old->fresh()->state);
    }

    public function test_ambiguous_suspend_response_never_supersedes(): void
    {
        $old = $this->simcard();
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldReceive('queryOrder')->twice()->andReturn(self::profile());
        $provider->shouldReceive('revokeEsim')->once()->andReturn(['errorCode' => '200002']);
        $provider->shouldReceive('suspendEsimByTransaction')->once()->andReturn([]);

        try {
            $this->service($provider)->retireForReplacement(self::PLAN_ID);
            self::fail('An empty response is not a provider rejection.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('did not return a recognized suspend result', $e->getMessage());
        }
        self::assertSame('active', $old->fresh()->state);
    }

    public function test_other_revoke_errors_never_enter_suspend_fallback(): void
    {
        $old = $this->simcard();
        $provider = Mockery::mock(EsimProvider::class);
        $provider->shouldReceive('queryOrder')->once()->andReturn(self::profile());
        $provider->shouldReceive('revokeEsim')->once()->andReturn(['errorCode' => '200010']);
        $provider->shouldReceive('suspendEsimByTransaction')->never();

        try {
            $this->service($provider)->retireForReplacement(self::PLAN_ID);
            self::fail('Only the approved revoke error can enter fallback.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Provider error code: 200010', $e->getMessage());
        }
        self::assertSame('active', $old->fresh()->state);
    }
}
