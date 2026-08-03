<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->timestamp('purchased_on')->nullable()->after('user_id');
            $table->index('purchased_on');
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->dropIndex(['purchased_on']);
            $table->dropColumn('purchased_on');
        });
    }
};
