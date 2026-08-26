<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$root = realpath(__DIR__.'/..');
$service = file_get_contents($root.'/app/Services/Support/EsimSupportReplacementService.php');
$routes = file_get_contents($root.'/routes/api.php');

$assert(str_contains($service, 'preg_replace(\'/\\s+/\', \'\', trim($planId))'), 'SIM ID normalization must remove all whitespace.');
$assert(str_contains($service, 'preg_match(\'/^\\d{16}$/\', $planId)'), 'SIM ID must remain exactly 16 digits.');
$assert(str_contains($service, '(int) $used !== 0'), 'Replacement execution must recheck live provider usage exactly zero.');
$assert(str_contains($service, 'topupDiagnostics'), 'Owned SIM inspection must expose safe top-up diagnostics.');
$assert(str_contains($service, 'supportLifecycle'), 'Owned SIM inspection must expose explicit support lifecycle diagnostics.');
$assert(str_contains($service, "'USAGE_UNKNOWN'"), 'Replacement blocks must explain unknown usage explicitly.');
$assert(str_contains($service, "'USAGE_DETECTED'"), 'Replacement blocks must explain detected usage explicitly.');
$assert(str_contains($service, "Schema::hasTable('simcard_auto_topup_attempts')"), 'Auto Top-Up attempts must be diagnosed only when the table exists.');
$assert(str_contains($service, "Schema::hasTable('simcard_topup_sessions')"), 'Top-Up sessions must be diagnosed only when the table exists.');
$assert(! str_contains($service, "'stripe_payment_intent_id' =>"), 'Support SIM diagnostics must not expose Stripe PaymentIntent IDs.');
$assert(str_contains($service, 'commerceOrderId: null'), 'Replacement must not reuse the original Commerce uniqueness tuple.');
$assert(str_contains($routes, "Route::prefix('v1/support/sim')"), 'Support routes must remain under the guarded support prefix.');

fwrite(STDOUT, "OK: Stellar SIM support patch smoke checks passed.\n");
