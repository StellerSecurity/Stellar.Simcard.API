<?php

return [
    // Enabled by the production deployment pipeline. Console commands are
    // excluded in AppServiceProvider to avoid recursively invoking Artisan.
    'auto_migrate' => env('AUTO_MIGRATE', false),
    'migration_lock_name' => env('MIGRATION_LOCK_NAME', 'stellar_simcard_api_migrations'),
    'migration_lock_timeout_seconds' => env('MIGRATION_LOCK_TIMEOUT_SECONDS', 60),
];
