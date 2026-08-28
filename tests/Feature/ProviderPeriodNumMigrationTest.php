<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProviderPeriodNumMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('simcards');
        Schema::create('simcards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('package_code');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('simcards');

        parent::tearDown();
    }

    public function test_provider_period_num_migration_is_reversible_and_idempotent(): void
    {
        $migration = require database_path('migrations/2026_08_25_000001_add_provider_period_num_to_simcards_table.php');

        self::assertFalse(Schema::hasColumn('simcards', 'provider_period_num'));

        $migration->up();
        $migration->up();

        self::assertTrue(Schema::hasColumn('simcards', 'provider_period_num'));

        $migration->down();
        $migration->down();

        self::assertFalse(Schema::hasColumn('simcards', 'provider_period_num'));
    }
}
