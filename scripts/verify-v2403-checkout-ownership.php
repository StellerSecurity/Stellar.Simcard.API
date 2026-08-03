<?php

function requireContains(string $file, string $needle, string $message): void
{
    $contents = file_get_contents($file);
    if (!is_string($contents) || !str_contains($contents, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$controller = $root.'/app/Http/Controllers/V1/SimcardController.php';
$service = $root.'/app/Services/SimcardService.php';

requireContains($controller, "'user_id' => ['nullable', 'integer', 'min:1']", 'SIM order user_id validation is missing');
requireContains($controller, "'account_linked' => \$result['simcard']->user_ref !== null", 'ownership acknowledgement is missing');
requireContains($service, "'user_ref'               => \$userId !== null", 'user_ref is not created during SIM order');
requireContains($service, "\$this->attachUserReference(\$existing, \$userId, 'purchase')", 'idempotent retry must repair anonymous ownership');

echo "SIM API v2.40.3 ownership verification passed.\n";
