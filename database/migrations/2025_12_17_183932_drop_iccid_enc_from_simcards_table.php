<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            if (Schema::hasColumn('simcards', 'iccid_enc')) {
                $table->dropColumn('iccid_enc');
            }
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->text('iccid_enc')->nullable();
        });
    }
};
