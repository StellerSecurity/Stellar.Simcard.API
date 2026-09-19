<?php

use App\Services\WholesaleSimcardSmsService;
use Mockery\MockInterface;

beforeEach(function (): void {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
});

function wholesaleSmsAuth(): array
{
    return ['Authorization' => 'Basic '.base64_encode('test-user:test-pass')];
}

it('sends a wholesale SMS without echoing its contents', function (): void {
    $orderId = '11111111-1111-4111-8111-111111111111';
    $itemId = '22222222-2222-4222-8222-222222222222';

    $this->mock(WholesaleSimcardSmsService::class, function (MockInterface $mock) use ($orderId, $itemId): void {
        $mock->shouldReceive('send')
            ->once()
            ->with('1234123412341234', $orderId, $itemId, 1, 'Your data is running low.')
            ->andReturn(true);
    });

    $response = $this->withHeaders(wholesaleSmsAuth())
        ->postJson('/api/v1/sim/sms', [
            'plan_id' => '1234 1234 1234 1234',
            'commerce_order_id' => $orderId,
            'commerce_order_item_id' => $itemId,
            'commerce_unit' => 1,
            'message' => '  Your data is running low.  ',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'sent');

    expect($response->getContent())->not->toContain('Your data is running low.');
});

it('rejects invalid or oversized message input before provider delivery', function (): void {
    $this->mock(WholesaleSimcardSmsService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('send');
    });

    $this->withHeaders(wholesaleSmsAuth())
        ->postJson('/api/v1/sim/sms', [
            'plan_id' => '1234123412341234',
            'commerce_order_id' => '11111111-1111-4111-8111-111111111111',
            'commerce_order_item_id' => '22222222-2222-4222-8222-222222222222',
            'commerce_unit' => 1,
            'message' => str_repeat('x', 501),
        ])
        ->assertStatus(400)
        ->assertJsonPath('response_code', 400);
});

it('does not expose whether a mismatched wholesale identity exists', function (): void {
    $this->mock(WholesaleSimcardSmsService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')->once()->andReturn(false);
    });

    $this->withHeaders(wholesaleSmsAuth())
        ->postJson('/api/v1/sim/sms', [
            'plan_id' => '1234123412341234',
            'commerce_order_id' => '11111111-1111-4111-8111-111111111111',
            'commerce_order_item_id' => '22222222-2222-4222-8222-222222222222',
            'commerce_unit' => 1,
            'message' => 'Service message',
        ])
        ->assertNotFound()
        ->assertJsonPath('response_code', 404);
});

it('blocks retired eSIM profiles', function (): void {
    $this->mock(WholesaleSimcardSmsService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')
            ->once()
            ->andThrow(new \DomainException('SMS is unavailable for a retired eSIM.'));
    });

    $this->withHeaders(wholesaleSmsAuth())
        ->postJson('/api/v1/sim/sms', [
            'plan_id' => '1234123412341234',
            'commerce_order_id' => '11111111-1111-4111-8111-111111111111',
            'commerce_order_item_id' => '22222222-2222-4222-8222-222222222222',
            'commerce_unit' => 1,
            'message' => 'Service message',
        ])
        ->assertStatus(409)
        ->assertJsonPath('response_code', 409);
});
