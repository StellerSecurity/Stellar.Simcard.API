<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            // All existing eSIMs predate the credential rotation and belong to legacy.
            // New orders explicitly store primary in SimcardService.
            $table->string('provider_account', 20)->default('legacy')->index()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->dropIndex(['provider_account']);
            $table->dropColumn('provider_account');
        });
    }
};
