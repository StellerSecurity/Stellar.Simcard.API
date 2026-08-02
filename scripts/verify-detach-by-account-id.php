<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root.'/app/Http/Controllers/V1/SimcardController.php');
$service = file_get_contents($root.'/app/Services/SimcardService.php');

$checks = [
    [str_contains($controller, "'simcard_id' => ['nullable', 'uuid', 'required_without:plan_id']"), 'UUID validation'],
    [str_contains($controller, 'detachUserById('), 'UUID detach dispatch'],
    [str_contains($service, 'public function detachUserById(string $simcardId, int $userId): array'), 'UUID service method'],
    [str_contains($service, 'whereKey($simcardId)'), 'UUID database lookup'],
    [str_contains($service, '$this->userReferences->matches('), 'verified owner check'],
    [str_contains($service, 'detachUserByPlanId(string $planId, int $userId)'), 'private SIM ID fallback'],
];

foreach ($checks as [$passed, $label]) {
    if (! $passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
