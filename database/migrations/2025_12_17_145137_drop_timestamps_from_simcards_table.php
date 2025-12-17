<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            if (Schema::hasColumn('simcards', 'created_at')) {
                $table->dropColumn('created_at');
            }

            if (Schema::hasColumn('simcards', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            if (! Schema::hasColumn('simcards', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('simcards', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }
};
