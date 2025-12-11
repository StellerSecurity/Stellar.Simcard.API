<?php

namespace App\Services\Esim;

interface EsimProvider
{
    public function listPlans(array $filters = []): array;

    public function createOrder(string $packageCode): EsimProviderOrder;

    public function queryOrder(string $externalOrderId): array;
}
