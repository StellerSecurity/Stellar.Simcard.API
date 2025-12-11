<?php

namespace App\Services\Esim;

class EsimProviderOrder
{
    public function __construct(
        public string $externalOrderId,
        public array $raw,
    ) {}
}
