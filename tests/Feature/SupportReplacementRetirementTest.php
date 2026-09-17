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

it('revokes a deleted installed profile with exactly zero usage before replacement', function (): void {
    $planId = '4538034324401446';
    $crypto = app(EsimCryptoService::class);
    $simcard = supportRetirementSimcard($planId, $crypto);

    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryOrder')
        ->once()
        ->with('B26091323430015', 'primary')
        ->andReturn(supportRetirementProfile('DELETED', 'IN_USE', 0));
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
