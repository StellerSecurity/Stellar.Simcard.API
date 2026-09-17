<?php

namespace Tests\Unit;

use App\Services\Esim\EsimaccessProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReplacementProviderResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::clearResolvedInstance(Factory::class);
        parent::tearDown();
    }

    #[DataProvider('transportFailures')]
    public function test_transport_errors_cannot_be_treated_as_provider_rejections(string $method, string $path, int $status): void
    {
        Http::fake([
            'https://provider.test'.$path => Http::response([
                'success' => false,
                'errorCode' => '200002',
                'errorMsg' => 'A structured body does not make transport failure conclusive.',
            ], $status),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('request failed with HTTP '.$status.'.');

        $this->provider()->{$method}('TEST-TRANSACTION', 'primary');
    }

    public static function transportFailures(): iterable
    {
        foreach (self::retirementMethods() as $method => $path) {
            foreach ([401, 403, 408, 429, 500, 503] as $status) {
                yield $method.' HTTP '.$status => [$method, $path, $status];
            }
        }
    }

    #[DataProvider('businessRejections')]
    public function test_structured_business_rejections_remain_available_to_guarded_retirement(string $method, string $path, int $status): void
    {
        $rejection = ['success' => false, 'errorCode' => '200002', 'errorMsg' => 'Status does not support action.'];
        Http::fake(['https://provider.test'.$path => Http::response($rejection, $status)]);

        $this->assertSame($rejection, $this->provider()->{$method}('TEST-TRANSACTION', 'primary'));
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://provider.test'.$path
            && $request->method() === 'POST'
            && $request->data() === ['esimTranNo' => 'TEST-TRANSACTION']);
    }

    public static function businessRejections(): iterable
    {
        foreach (self::retirementMethods() as $method => $path) {
            foreach ([200, 400, 409, 422] as $status) {
                yield $method.' HTTP '.$status => [$method, $path, $status];
            }
        }
    }

    #[DataProvider('invalidResponses')]
    public function test_unstructured_responses_cannot_authorize_retirement(string $method, string $path, string $body, int $status): void
    {
        Http::fake(['https://provider.test'.$path => Http::response($body, $status, ['Content-Type' => 'application/json'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('response was not a valid JSON object.');

        $this->provider()->{$method}('TEST-TRANSACTION', 'primary');
    }

    public static function invalidResponses(): iterable
    {
        foreach (self::retirementMethods() as $method => $path) {
            foreach ([200, 422] as $status) {
                foreach (['malformed' => '{broken', 'list' => '[{"success":false,"errorCode":"200002"}]', 'scalar' => 'false', 'null' => 'null'] as $name => $body) {
                    yield $method.' '.$name.' HTTP '.$status => [$method, $path, $body, $status];
                }
            }
        }
    }

    #[DataProvider('retirementCalls')]
    public function test_connection_failure_propagates_without_retirement_response(string $method, string $path): void
    {
        Http::fake(['https://provider.test'.$path => static function (): never {
            throw new ConnectionException('Simulated connection failure.');
        }]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Simulated connection failure.');

        $this->provider()->{$method}('TEST-TRANSACTION', 'primary');
    }

    public static function retirementCalls(): iterable
    {
        foreach (self::retirementMethods() as $method => $path) {
            yield $method => [$method, $path];
        }
    }

    private static function retirementMethods(): array
    {
        return [
            'cancelEsim' => '/v1/open/esim/cancel',
            'revokeEsim' => '/v1/open/esim/revoke',
            'suspendEsimByTransaction' => '/v1/open/esim/suspend',
        ];
    }

    private function provider(): EsimaccessProvider
    {
        return new EsimaccessProvider(
            baseUrl: 'https://provider.test',
            accounts: ['primary' => ['access_code' => 'dummy-test-access', 'secret_key' => 'dummy-test-secret']],
        );
    }
}
