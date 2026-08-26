<?php

declare(strict_types=1);

$root = realpath(__DIR__.'/..');
$service = file_get_contents($root.'/app/Services/Support/EsimSupportReplacementService.php');
$migration = file_get_contents($root.'/database/migrations/2026_08_26_000300_create_simcard_support_replacements_table.php');
$routes = file_get_contents($root.'/routes/api.php');

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($service, "preg_replace('/\\s+/'"), 'SIM IDs must remove all whitespace.');
$assert(str_contains($service, "preg_match('/^\\d{16}$/'"), 'SIM IDs must be exactly 16 digits.');
$assert(str_contains($service, '(int) $used !== 0'), 'Replacement must re-check live provider usage and require exactly zero bytes.');
$assert(str_contains($service, '$this->cancellations->cancel($planId)'), 'Old eSIM cancellation must be part of the guarded saga.');
$assert(! str_contains($service, "'commerce_order_id' => \$old->commerce_order_id"), 'Replacement must not duplicate the unique Commerce fulfillment tuple.');
$assert(str_contains($migration, "unique('old_simcard_id'"), 'Only one support replacement may exist per old eSIM.');
$assert(str_contains($routes, "'/replace-unused'"), 'Guarded support replacement route must be registered.');

fwrite(STDOUT, "OK: SIM support replacement checks passed.\n");
