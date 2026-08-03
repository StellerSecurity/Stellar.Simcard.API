<?php

declare(strict_types=1);

$read = static fn (string $path): string => file_get_contents($path) ?: '';
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$service = $read('app/Services/SimcardService.php');
$topup = $read('app/Services/TopupService.php');
$model = $read('app/Models/Simcard.php');
$originalMigration = $read('database/migrations/2026_01_06_182317_add_purchased_on_to_simcards_table.php');
$upgradeMigration = $read('database/migrations/2026_08_03_000002_use_timestamp_for_purchased_on.php');

$assert(str_contains($service, "'purchased_on'           => now()"), 'New eSIM purchases must store the exact timestamp in purchased_on.');
$assert(! str_contains($service, "'purchased_at'"), 'Runtime service must not use purchased_at.');
$assert(str_contains($service, "->orderByDesc('purchased_on')"), 'User eSIM list must sort by purchased_on descending.');
$assert(str_contains($service, "'purchased_on' => \$simcard->purchased_on?->toIso8601String()"), 'Account payload must expose purchased_on as ISO date-time.');
$assert(str_contains($service, "'user_link_source'       => \$userId !== null ? 'purchase' : null"), 'Signed-in purchases must attach the privacy-preserving user reference.');
$assert(str_contains($model, "'purchased_on' => 'datetime'"), 'Simcard purchased_on datetime cast is missing.');
$assert(! str_contains($model, "'purchased_at'"), 'Simcard model must not expose purchased_at.');
$assert(str_contains($originalMigration, "timestamp('purchased_on')"), 'Fresh databases must create purchased_on as a timestamp.');
$assert(str_contains($upgradeMigration, 'MODIFY purchased_on TIMESTAMP') && str_contains($upgradeMigration, "dropColumn('purchased_at')"), 'Production upgrade migration must convert purchased_on and remove legacy purchased_at.');
$assert(substr_count($topup, '$this->assertTopupEligible($simcard);') >= 5, 'Every top-up entry point must enforce IN_USE eligibility.');
$assert(str_contains($topup, "\$providerStatus === 'IN_USE'"), 'Provider IN_USE status must be authoritative for top-up.');
$assert(str_contains($topup, 'Only eSIMs currently in use can be topped up.'), 'Top-up 409 error copy is missing.');

echo "SIM API v2.40.1 release verification passed.\n";
