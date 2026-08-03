<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('simcards', 'purchased_on')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->timestamp('purchased_on')->nullable()->index();
            });
        } else {
            $this->changePurchasedOnToTimestamp();
        }

        // Compatibility for servers where the earlier v2.40 migration already
        // ran. Preserve the precise value, then return to one canonical column.
        if (Schema::hasColumn('simcards', 'purchased_at')) {
            DB::table('simcards')
                ->whereNotNull('purchased_at')
                ->update(['purchased_on' => DB::raw('purchased_at')]);

            try {
                Schema::table('simcards', function (Blueprint $table): void {
                    $table->dropIndex(['purchased_at']);
                });
            } catch (\Throwable) {
                // The index may not exist on every environment.
            }

            Schema::table('simcards', function (Blueprint $table): void {
                $table->dropColumn('purchased_at');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE simcards MODIFY purchased_on DATE NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE simcards ALTER COLUMN purchased_on TYPE DATE USING purchased_on::date');
            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE simcards ALTER COLUMN purchased_on DATE NULL');
        }
        // SQLite accepts timestamp strings in the existing column and does not
        // require a destructive table rebuild for rollback in tests.
    }

    private function changePurchasedOnToTimestamp(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE simcards MODIFY purchased_on TIMESTAMP NULL DEFAULT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE simcards ALTER COLUMN purchased_on TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING purchased_on::timestamp');
            return;
        }

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE simcards ALTER COLUMN purchased_on DATETIME2 NULL');
        }
        // SQLite is dynamically typed. Existing DATE columns already preserve
        // full ISO date-time strings without a schema rebuild.
    }
};
