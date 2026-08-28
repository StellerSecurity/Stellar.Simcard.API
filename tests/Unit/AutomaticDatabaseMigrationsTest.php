<?php

use App\Services\Deployment\AutomaticDatabaseMigrations;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

it('does nothing when automatic migrations are disabled', function (): void {
    config()->set('deployment.auto_migrate', false);

    DB::shouldReceive('connection')->never();
    Artisan::shouldReceive('call')->never();

    (new AutomaticDatabaseMigrations())->run();
});

it('runs forced migrations once for a non mysql connection', function (): void {
    config()->set('deployment.auto_migrate', true);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('Nothing to migrate.');
    Log::shouldReceive('info')->once();

    $migrations = new AutomaticDatabaseMigrations();
    $migrations->run();
    $migrations->run();
});

it('serializes mysql migrations with an advisory lock', function (): void {
    config()->set('deployment.auto_migrate', true);
    config()->set('deployment.migration_lock_name', 'test_migrations');
    config()->set('deployment.migration_lock_timeout_seconds', 15);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT GET_LOCK(?, ?) AS acquired', ['test_migrations', 15])
        ->andReturn((object) ['acquired' => 1]);
    $connection->shouldReceive('selectOne')
        ->once()
        ->with('SELECT RELEASE_LOCK(?) AS released', ['test_migrations'])
        ->andReturn((object) ['released' => 1]);
    DB::shouldReceive('connection')->once()->andReturn($connection);
    Artisan::shouldReceive('call')->once()->andReturn(0);
    Artisan::shouldReceive('output')->once()->andReturn('Migrated.');
    Log::shouldReceive('info')->once();

    (new AutomaticDatabaseMigrations())->run();
});
