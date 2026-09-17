<?php

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use App\Services\UnusedEsimCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function supportRetirementProfile(string $smdpStatus, string $esimStatus, int $usage): array
{
    return [
        'success' => true,
        'obj' => [
            'esimList' => [[
                'esimTranNo' => '26091323430015',
                'smdpStatus' => $smdpStatus,
                'esimStatus' => $esimStatus,
                'orderUsage' => $usage,
            ]],
        ],
    ];
}

function supportRetirementSimcard(string $planId, EsimCryptoService $crypto): Simcard
{
    return Simcard::query()->create([
        'plan_id_hash' => $crypto->derivePlanHash($planId),
        'provider' => 'esimaccess',
        'provider_account' => 'primary',
        'package_code' => 'CA-75GB-20D',
        'external_order_id_enc' => $crypto->encryptForPlan($planId, 'B26091323430015'),
        'state' => 'active',
        'esim_status' => 'IN_USE',
        'smdp_status' => 'DELETED',
        'order_usage' => 0,
        'remaining_volume' => 80530636800,
    ]);
}

beforeEach(function (): void {
    config([
        'esim.crypto.hash_key' => 'support-retirement-test-hash-key',
        'esim.crypto.master_key' => 'support-retirement-test-master-key',
    ]);
});

it('revokes an active installed profile with exactly zero usage before replacement', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    $simcard = supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->with('B26091323430015', 'primary')
        ->andReturn(supportRetirementProfile('ENABLED', 'IN_USE', 0));
    $provider->shouldReceive('revokeEsim')
        ->once()
        ->with('26091323430015', 'primary')
        ->andReturn(['success' => true, 'errorCode' => '0']);
    $provider->shouldReceive('cancelEsim')->never();
    $provider->shouldReceive('queryOrder')
        ->once()
        ->with('B26091323430015', 'primary')
        ->andReturn(supportRetirementProfile('DELETED', 'REVOKED', 0));

    $result = (new UnusedEsimCancellationService($provider, $crypto))
        ->retireForReplacement($planId);

    expect($result)
        ->toMatchArray([
            'status' => 'revoked',
            'retirement_action' => 'revoke',
        ])
        ->and($simcard->fresh()->state)->toBe('cancelled')
        ->and($simcard->fresh()->esim_status)->toBe('REVOKED')
        ->and((int) $simcard->fresh()->remaining_volume)->toBe(0);
});

it('continues replacement without revoke when the fresh zero-usage profile is already deleted', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    $simcard = supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->with('B26091323430015', 'primary')
        ->andReturn(supportRetirementProfile('DELETED', 'IN_USE', 0));
    $provider->shouldReceive('revokeEsim')->never();
    $provider->shouldReceive('cancelEsim')->never();

    $result = (new UnusedEsimCancellationService($provider, $crypto))
        ->retireForReplacement($planId);

    expect($result)->toMatchArray([
        'status' => 'already_deleted',
        'retirement_action' => 'provider_profile_already_deleted',
    ])->and($simcard->fresh()->state)->toBe('cancelled')
        ->and($simcard->fresh()->smdp_status)->toBe('DELETED')
        ->and((int) $simcard->fresh()->order_usage)->toBe(0);
});

it('continues replacement when live SM-DP status becomes unsupported after a persisted deletion', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    $simcard = supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->andReturn(supportRetirementProfile('NOT_SUPPORTED', 'IN_USE', 0));
    $provider->shouldReceive('revokeEsim')->never();
    $provider->shouldReceive('cancelEsim')->never();

    $result = (new UnusedEsimCancellationService($provider, $crypto))
        ->retireForReplacement($planId);

    expect($result)->toMatchArray([
        'status' => 'already_deleted',
        'retirement_action' => 'provider_profile_already_deleted',
    ])->and($simcard->fresh()->state)->toBe('cancelled')
        ->and((int) $simcard->fresh()->order_usage)->toBe(0);
});

it('suspends a zero-usage profile when revoke is unavailable and deletion is not proven', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    $simcard = supportRetirementSimcard($planId, $crypto);
    $simcard->forceFill(['smdp_status' => 'ENABLED'])->save();

    $provider = Mockery::mock(EsimProvider::class);
    $before = supportRetirementProfile('NOT_SUPPORTED', 'IN_USE', 0);
    $before['obj']['esimList'][0]['iccid'] = '8945000000000000000';
    $provider->shouldReceive('queryOrder')
        ->twice()
        ->andReturn($before);
    $provider->shouldReceive('revokeEsim')
        ->once()
        ->andReturn(['errorCode' => '200002']);
    $provider->shouldReceive('suspendEsim')
        ->once()
        ->with('8945000000000000000', 'primary')
        ->andReturn(['success' => true, 'errorCode' => '0']);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->andReturn(supportRetirementProfile('DISABLED', 'SUSPENDED', 0));
    $provider->shouldReceive('cancelEsim')->never();

    $result = (new UnusedEsimCancellationService($provider, $crypto))->retireForReplacement($planId);

    expect($result)->toMatchArray([
        'status' => 'suspended',
        'retirement_action' => 'suspend_after_revoke_unavailable',
    ])->and($simcard->fresh()->state)->toBe('cancelled')
        ->and($simcard->fresh()->esim_status)->toBe('SUSPENDED')
        ->and($simcard->fresh()->smdp_status)->toBe('DISABLED')
        ->and((int) $simcard->fresh()->order_usage)->toBe(0);
});

it('does not provision after a suspension fallback until the provider confirms it', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    supportRetirementSimcard($planId, $crypto)->forceFill(['smdp_status' => 'ENABLED'])->save();

    $before = supportRetirementProfile('NOT_SUPPORTED', 'IN_USE', 0);
    $before['obj']['esimList'][0]['iccid'] = '8945000000000000000';

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')->times(6)->andReturn($before);
    $provider->shouldReceive('revokeEsim')->once()->andReturn(['errorCode' => '200002']);
    $provider->shouldReceive('suspendEsim')->once()->andReturn(['success' => true, 'errorCode' => '0']);
    $provider->shouldReceive('cancelEsim')->never();

    expect(fn () => (new UnusedEsimCancellationService($provider, $crypto))->retireForReplacement($planId))
        ->toThrow(RuntimeException::class, 'has not confirmed that the old eSIM is suspended');
});

it('keeps the ordinary cancellation path from silently revoking installed profiles', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->andReturn(supportRetirementProfile('ENABLED', 'IN_USE', 0));
    $provider->shouldReceive('revokeEsim')->never();
    $provider->shouldReceive('cancelEsim')->never();

    expect(fn () => (new UnusedEsimCancellationService($provider, $crypto))->cancel($planId))
        ->toThrow(DomainException::class, 'installed and cannot be cancelled automatically');
});

it('continues replacement when revoke confirms the zero-usage profile is already deleted', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    $simcard = supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->with('B26091323430015', 'primary')
        ->andReturn(supportRetirementProfile('ENABLED', 'IN_USE', 0));
    $provider->shouldReceive('revokeEsim')
        ->once()
        ->with('26091323430015', 'primary')
        // Provider business errors do not consistently include success=false.
        ->andReturn(['errorCode' => '200002']);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->with('B26091323430015', 'primary')
        ->andReturn(supportRetirementProfile('DELETED', 'IN_USE', 0));
    $provider->shouldReceive('cancelEsim')->never();

    $result = (new UnusedEsimCancellationService($provider, $crypto))
        ->retireForReplacement($planId);

    expect($result)->toMatchArray([
        'status' => 'already_deleted',
        'retirement_action' => 'revoke_unavailable_profile_deleted',
    ])->and($simcard->fresh()->state)->toBe('cancelled')
        ->and($simcard->fresh()->smdp_status)->toBe('DELETED')
        ->and((int) $simcard->fresh()->order_usage)->toBe(0);
});

it('does not bypass a revoke rejection when the fresh profile is not deleted', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->andReturn(supportRetirementProfile('ENABLED', 'IN_USE', 0));
    $provider->shouldReceive('revokeEsim')
        ->once()
        ->andReturn(['success' => false, 'errorCode' => '200002']);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->andReturn(supportRetirementProfile('ENABLED', 'IN_USE', 0));
    $provider->shouldReceive('cancelEsim')->never();

    expect(fn () => (new UnusedEsimCancellationService($provider, $crypto))->retireForReplacement($planId))
        ->toThrow(DomainException::class, 'no longer eligible for revoke');
});

it('blocks replacement retirement when live provider usage is positive', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->andReturn(supportRetirementProfile('ENABLED', 'IN_USE', 1));
    $provider->shouldReceive('revokeEsim')->never();
    $provider->shouldReceive('cancelEsim')->never();

    expect(fn () => (new UnusedEsimCancellationService($provider, $crypto))->retireForReplacement($planId))
        ->toThrow(DomainException::class, 'provider reports data usage');
});
