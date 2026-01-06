<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->dropColumn('account_ref');
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->string('account_ref', 191)->nullable();
        });
    }
};
