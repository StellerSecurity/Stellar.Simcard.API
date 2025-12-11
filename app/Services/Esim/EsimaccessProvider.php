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
            accessCode: config('services.esimaccess.access_code'),
            secretKey: config('services.esimaccess.secret_key'),
        );
    }

    private function createHeaders(array $data): array
    {
        $requestId = (string) Str::uuid();
        $timestamp = (int) floor(microtime(true) * 1000);

        $signStr = $timestamp . $requestId . $this->accessCode . json_encode($data);
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

        $response = Http::withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . '/v1/open/package/list', $payload)
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

        $response = Http::withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . '/v1/open/esim/order', $payload)
            ->throw();

        $body = $response->json();

        $externalOrderId =
            $body['data']['orderNo']
            ?? $body['orderNo']
            ?? throw new \RuntimeException('No orderNo in provider response');

        return new EsimProviderOrder($externalOrderId, $body);
    }

    public function queryOrder(string $externalOrderId): array
    {
        $payload = [
            'orderNo' => $externalOrderId,
            'pager'   => [
                'pageNum'  => 1,
                'pageSize' => 20,
            ],
        ];

        $response = Http::withHeaders($this->createHeaders($payload))
            ->post($this->baseUrl . '/v1/open/esim/query', $payload)
            ->throw();

        return $response->json();
    }
}
