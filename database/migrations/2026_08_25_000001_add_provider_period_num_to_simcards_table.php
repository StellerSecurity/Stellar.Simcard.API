<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('simcards', 'provider_period_num')) {
            Schema::table('simcards', function (Blueprint $table): void {
                // Selected number of days for eSIMAccess dataType=2 Daily/Unlimited
                // plans. Fixed-data plans keep this NULL.
                $table->unsignedSmallInteger('provider_period_num')
                    ->nullable()
                    ->after('package_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('simcards', 'provider_period_num')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->dropColumn('provider_period_num');
            });
        }
    }
};
