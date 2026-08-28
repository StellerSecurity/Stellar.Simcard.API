<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class AutomaticDatabaseMigrations
{
    private bool $completed = false;

    public function run(): void
    {
        $enabled = filter_var(config('deployment.auto_migrate', false), FILTER_VALIDATE_BOOLEAN);

        if ($this->completed || ! $enabled) {
            return;
        }

        $connection = DB::connection();
        $usesMysqlLock = $connection->getDriverName() === 'mysql';
        $lockName = (string) config('deployment.migration_lock_name', 'stellar_simcard_api_migrations');
        $lockTimeout = max(1, (int) config('deployment.migration_lock_timeout_seconds', 60));
        $lockAcquired = false;

        if ($usesMysqlLock) {
            $result = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, $lockTimeout]);
            $lockAcquired = (int) ($result->acquired ?? 0) === 1;

            if (! $lockAcquired) {
                throw new RuntimeException('Could not acquire the automatic database migration lock.');
            }
        }

        try {
            $exitCode = Artisan::call('migrate', [
                '--force' => true,
                '--no-interaction' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException('Automatic database migrations failed with exit code '.$exitCode.'.');
            }

            $this->completed = true;

            Log::info('Automatic database migrations completed.', [
                'output' => trim(Artisan::output()),
            ]);
        } finally {
            if ($usesMysqlLock && $lockAcquired) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        }
    }
}
