<?php

use App\Exceptions\SimcardOwnershipConflictException;
use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\Esim\SimcardUserReferenceService;
use App\Services\SimcardService;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('esim.user_reference.current_version', 1);
    config()->set('esim.user_reference.keys.1', str_repeat('a', 64));
});

function accountDetachService(SimcardUserReferenceService $references): SimcardService
{
    return new SimcardService(
        Mockery::mock(EsimProvider::class),
        Mockery::mock(EsimCryptoService::class),
        Mockery::mock(EsimMarketingRefundOfferService::class),
        $references,
        Mockery::mock(VirtualEsimQuotaService::class),
    );
}

function accountLinkedSimcard(SimcardUserReferenceService $references, int $userId): Simcard
{
    return Simcard::forceCreate([
        'id' => '00000000-0000-4000-8000-000000000001',
        'plan_id_hash' => 'v1:'.str_repeat('1', 64),
        'provider' => 'esimaccess',
        'provider_account' => 'primary',
        'package_code' => 'DK_10GB_30DAYS',
        'external_order_id_enc' => 'ciphertext',
        'state' => 'OK',
        'user_ref' => $references->derive($userId),
        'user_ref_version' => $references->currentVersion(),
        'user_linked_at' => now(),
        'user_link_source' => 'mobile_app',
        'purchased_on' => now(),
    ]);
}

it('detaches an account eSIM by UUID without the private SIM ID', function (): void {
    $references = app(SimcardUserReferenceService::class);
    $simcard = accountLinkedSimcard($references, 7345);

    $result = accountDetachService($references)->detachUserById($simcard->id, 7345);

    expect($result['status'])->toBe('detached')
        ->and($simcard->fresh()->user_ref)->toBeNull()
        ->and($simcard->fresh()->user_id)->toBeNull();
});

it('does not detach an account eSIM owned by another user', function (): void {
    $references = app(SimcardUserReferenceService::class);
    $simcard = accountLinkedSimcard($references, 7345);

    expect(fn () => accountDetachService($references)->detachUserById($simcard->id, 9001))
        ->toThrow(SimcardOwnershipConflictException::class);

    expect($simcard->fresh()->user_ref)->toBe($references->derive(7345));
});
