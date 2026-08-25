<?php

namespace App\Services\Esim;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EsimaccessProvider implements EsimProvider
{
    public const ACCOUNT_PRIMARY = 'primary';
    public const ACCOUNT_LEGACY = 'legacy';

    public function __construct(
        private readonly string $baseUrl,
        private readonly array $accounts,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('services.esimaccess.base_url'), '/'),
            accounts: (array) config('services.esimaccess.accounts', []),
        );
    }

    private function http()
    {
        return Http::timeout(30)->connectTimeout(10);
    }

    private function credentials(string $account): array
    {
        $account = $this->normalizeAccount($account);
        $credentials = $this->accounts[$account] ?? null;
        $accessCode = is_array($credentials) ? trim((string) ($credentials['access_code'] ?? '')) : '';
        $secretKey = is_array($credentials) ? trim((string) ($credentials['secret_key'] ?? '')) : '';

        if ($accessCode === '' || $secretKey === '') {
            throw new RuntimeException("eSIMAccess credentials are not configured for account [{$account}].");
        }

        return ['access_code' => $accessCode, 'secret_key' => $secretKey];
    }

    private function normalizeAccount(string $account): string
    {
        return $account === self::ACCOUNT_LEGACY ? self::ACCOUNT_LEGACY : self::ACCOUNT_PRIMARY;
    }

    private function createHeaders(array $data, string $account): array
    {
        $credentials = $this->credentials($account);
        $requestId = (string) Str::uuid();
        $timestamp = (int) floor(microtime(true) * 1000);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Could not encode eSIMAccess request payload.');
        }

        $signStr = $timestamp . $requestId . $credentials['access_code'] . $json;
        $sign = hash_hmac('sha256', $signStr, $credentials['secret_key']);

        return [
            'RT-AccessCode' => $credentials['access_code'],
            'RT-RequestID' => $requestId,
            'RT-Signature' => $sign,
            'RT-Timestamp' => $timestamp,
            'SecretKey' => $credentials['secret_key'],
        ];
    }

    public function listPlans(array $filters = [], string $account = self::ACCOUNT_PRIMARY): array
    {
        $payload = [
            'locationCode' => $filters['locationCode'] ?? '',
            'type' => $filters['type'] ?? '',
            'packageCode' => $filters['packageCode'] ?? '',
            'iccid' => $filters['iccid'] ?? '',
        ];

        // Additive filters used by Daily/Unlimited catalogue consumers. Do not add
        // these keys to legacy requests unless explicitly supplied, so the signed
        // fixed-plan request body remains unchanged.
        if (array_key_exists('slug', $filters)) {
            $payload['slug'] = $filters['slug'] ?? '';
        }

        if (array_key_exists('dataType', $filters)) {
            $payload['dataType'] = $filters['dataType'] ?? '';
        }

        return $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . '/v1/open/package/list', $payload)
            ->throw()
            ->json();
    }

    public function createOrder(
        string $packageCode,
        string $account = self::ACCOUNT_PRIMARY,
        ?int $periodNum = null
    ): EsimProviderOrder
    {
        if ($periodNum !== null && ($periodNum < 1 || $periodNum > 365)) {
            throw new RuntimeException('Daily/Unlimited eSIM duration must be between 1 and 365 days.');
        }

        $packageInfo = [
            'packageCode' => $packageCode,
            'count' => 1,
        ];

        // eSIMAccess calls this periodNum. It is required for dataType=2
        // Daily/Unlimited plans and must be omitted for the existing fixed plans.
        if ($periodNum !== null) {
            $packageInfo['periodNum'] = $periodNum;
        }

        $payload = [
            'transactionId' => Str::random(16),
            'packageInfoList' => [$packageInfo],
        ];

        $body = $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . '/v1/open/esim/order', $payload)
            ->throw()
            ->json();

        $externalOrderId = data_get($body, 'obj.orderNo')
            ?? data_get($body, 'data.orderNo')
            ?? data_get($body, 'orderNo');

        if (! is_string($externalOrderId) || $externalOrderId === '') {
            throw new RuntimeException('No orderNo in provider response.');
        }

        return new EsimProviderOrder($externalOrderId, $body);
    }

    public function queryOrder(string $externalOrderId, string $account = self::ACCOUNT_PRIMARY): array
    {
        return $this->queryEsim($externalOrderId, null, $account);
    }

    public function queryEsim(?string $externalOrderId = null, ?string $iccid = null, string $account = self::ACCOUNT_PRIMARY): array
    {
        $payload = [
            'orderNo' => $externalOrderId ?? '',
            'iccid' => $iccid ?? '',
            'pager' => ['pageNum' => 1, 'pageSize' => 20],
        ];

        return $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . '/v1/open/esim/query', $payload)
            ->throw()
            ->json();
    }

    public function resolveAccountForEsim(?string $externalOrderId = null, ?string $iccid = null, string $preferredAccount = self::ACCOUNT_LEGACY): string
    {
        if (($externalOrderId === null || trim($externalOrderId) === '') && ($iccid === null || trim($iccid) === '')) {
            throw new RuntimeException('An order number or ICCID is required to resolve the eSIMAccess account.');
        }

        $preferredAccount = $this->normalizeAccount($preferredAccount);
        $accounts = array_values(array_unique([
            $preferredAccount,
            $preferredAccount === self::ACCOUNT_PRIMARY ? self::ACCOUNT_LEGACY : self::ACCOUNT_PRIMARY,
        ]));
        $lastException = null;

        foreach ($accounts as $account) {
            try {
                $response = $this->queryEsim($externalOrderId, $iccid, $account);

                if ($this->containsEsim($response)) {
                    return $account;
                }
            } catch (ConnectionException $exception) {
                // An unknown network result must not trigger cross-account write behavior.
                throw $exception;
            } catch (RequestException $exception) {
                $lastException = $exception;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException !== null) {
            throw new RuntimeException('Could not resolve the eSIMAccess account.', 0, $lastException);
        }

        throw new RuntimeException('The eSIM was not found in either configured eSIMAccess account.');
    }

    private function containsEsim(array $response): bool
    {
        $list = data_get($response, 'obj.esimList');

        return is_array($list) && count($list) > 0;
    }

    public function sendSms(string $iccid, string $message, string $account = self::ACCOUNT_PRIMARY): array
    {
        $payload = ['iccid' => $iccid, 'message' => $message];

        return $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . '/v1/open/esim/sendSms', $payload)
            ->throw()
            ->json();
    }

    public function cancelEsim(string $esimTranNo, string $account = self::ACCOUNT_PRIMARY): array
    {
        $payload = ['esimTranNo' => $esimTranNo];

        $response = $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . '/v1/open/esim/cancel', $payload);

        $body = $response->json();

        // Provider eligibility failures are valid business responses. Return
        // JSON bodies to the caller so they can be mapped without hiding the
        // provider's state behind a generic HTTP exception.
        if (is_array($body)) {
            return $body;
        }

        $response->throw();

        throw new RuntimeException('The eSIMAccess cancellation response was not valid JSON.');
    }

    public function suspendEsim(string $iccid, string $account = self::ACCOUNT_PRIMARY): array
    {
        $payload = ['iccid' => trim($iccid)];

        return $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . '/v1/open/esim/suspend', $payload)
            ->throw()
            ->json();
    }

    public function unsuspendEsim(string $iccid, string $account = self::ACCOUNT_PRIMARY): array
    {
        $payload = ['iccid' => trim($iccid)];

        return $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . '/v1/open/esim/unsuspend', $payload)
            ->throw()
            ->json();
    }

    public function topup(string $iccid, string $packageCode, string $transactionId, string $account = self::ACCOUNT_PRIMARY): array
    {
        $payload = [
            'transactionId' => $transactionId,
            'iccid' => $iccid,
            'packageCode' => $packageCode,
        ];

        $path = '/' . ltrim((string) config('services.esimaccess.topup_path', '/v1/open/esim/topup'), '/');

        return $this->http()
            ->withHeaders($this->createHeaders($payload, $account))
            ->post($this->baseUrl . $path, $payload)
            ->throw()
            ->json();
    }
}
