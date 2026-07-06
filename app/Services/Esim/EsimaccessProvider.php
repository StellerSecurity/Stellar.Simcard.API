<?php

namespace App\Services\Esim;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EsimaccessProvider implements EsimProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $accessCode,
        private readonly string $secretKey,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim(config('services.esimaccess.base_url'), '/'),
            accessCode: (string) config('services.esimaccess.access_code'),
            secretKey: (string) config('services.esimaccess.secret_key'),
        );
    }

    /**
     * Create a shared HTTP client with sane timeouts.
     */
    private function http()
    {
        return Http::timeout(30)
            ->connectTimeout(10);
    }

    private function createHeaders(array $data): array
    {
        $requestId = (string) Str::uuid();
        $timestamp = (int) floor(microtime(true) * 1000);

        // IMPORTANT: Make JSON deterministic (avoid weird escaping differences)
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $signStr = $timestamp . $requestId . $this->accessCode . $json;
        $sign    = hash_hmac('sha256', $signStr, $this->secretKey);

        return [
            'RT-AccessCode' => $this->accessCode,
            'RT-RequestID'  => $requestId,
            'RT-Signature'  => $sign,
            'RT-Timestamp'  => $timestamp,
            'SecretKey'     => $this->secretKey,
        ];
    }

    public function listPlans(array $filters = []): array
    {
        $payload = [
            'locationCode' => $filters['locationCode'] ?? '',
            'type'         => $filters['type'] ?? '',
            'packageCode'  => $filters['packageCode'] ?? '',
            'iccid'        => $filters['iccid'] ?? '',
        ];

        $response = $this->http()
            ->withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . '/v1/open/package/list', $payload)
            ->throw();

        return $response->json();
    }


    public function listTopupPlans(string $iccid): array
    {
        $iccid = trim($iccid);

        if ($iccid === '') {
            throw new \InvalidArgumentException('ICCID is required for top-up package list.');
        }

        // eSIMAccess returns top-up-compatible packages when package/list is queried with ICCID.
        // Do not include locationCode/type/packageCode here; those can make provider return normal
        // sale packages, which are not necessarily valid for /esim/topup.
        $payload = [
            'iccid' => $iccid,
        ];

        $path = (string) config('services.esimaccess.topup_package_list_path', '/v1/open/package/list');
        $path = '/' . ltrim($path, '/');

        $response = $this->http()
            ->withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . $path, $payload)
            ->throw();

        return $response->json();
    }

    public function createOrder(string $packageCode): EsimProviderOrder
    {
        $transactionId = Str::random(16);

        $payload = [
            'transactionId'   => $transactionId,
            'packageInfoList' => [[
                'packageCode' => $packageCode,
                'count'       => 1,
            ]],
        ];

        $response = $this->http()
            ->withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . '/v1/open/esim/order', $payload)
            ->throw();

        $body = $response->json();

        $externalOrderId = data_get($body, 'obj.orderNo')
            ?? data_get($body, 'data.orderNo')
            ?? data_get($body, 'orderNo');

        if (!is_string($externalOrderId) || $externalOrderId === '') {
            throw new \RuntimeException('No orderNo in provider response.');
        }

        return new EsimProviderOrder($externalOrderId, $body);
    }

    public function queryOrder(string $externalOrderId): array
    {
        return $this->queryEsim($externalOrderId);
    }

    public function queryEsim(?string $externalOrderId = null, ?string $iccid = null): array
    {
        $payload = [
            'orderNo' => $externalOrderId ?? '',
            'iccid' => $iccid ?? '',
            'pager'   => [
                'pageNum'  => 1,
                'pageSize' => 20,
            ],
        ];

        $response = $this->http()
            ->withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . '/v1/open/esim/query', $payload)
            ->throw();

        return $response->json();
    }


    public function sendSms(string $iccid, string $message): array
    {
        $payload = [
            'iccid' => $iccid,
            'message' => $message,
        ];

        $response = $this->http()
            ->withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . '/v1/open/esim/sendSms', $payload)
            ->throw();

        return $response->json();
    }

    public function topup(string $iccid, string $packageCode, string $transactionId): array
    {
        $payload = [
            'transactionId' => $transactionId,
            'iccid' => $iccid,
            'packageCode' => $packageCode,
        ];

        $path = (string) config('services.esimaccess.topup_path', '/v1/open/esim/topup');
        $path = '/' . ltrim($path, '/');

        $response = $this->http()
            ->withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . $path, $payload)
            ->throw();

        return $response->json();
    }

}
