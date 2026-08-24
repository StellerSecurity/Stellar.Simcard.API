<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('simcards', 'virtual_fulfillment_recipe')) {
            Schema::table('simcards', function (Blueprint $table): void {
                // Internal, non-secret fulfillment recipe. It locks the exact BASE + TOPUP
                // composition so retries can never switch recipes after partial fulfillment.
                $table->json('virtual_fulfillment_recipe')->nullable()->after('package_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('simcards', 'virtual_fulfillment_recipe')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->dropColumn('virtual_fulfillment_recipe');
            });
        }
    }
};
