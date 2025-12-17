<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            // Make sure plan_id_hash can store "v1:" + 64 hex chars (and future versions)
            $table->string('plan_id_hash', 100)->nullable(false)->change();

            // Ensure lookup is fast and uniqueness is enforced
            $table->unique('plan_id_hash', 'simcards_plan_id_hash_uq');

            // If kiosk flow, user_id should be nullable (only if you want/need it)
            // Uncomment if your column is NOT NULL today.
            // $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            // Drop unique index first
            $table->dropUnique('simcards_plan_id_hash_uq');

            // Revert length (old)
            $table->string('plan_id_hash', 64)->nullable(false)->change();

            // Revert user_id (only if you changed it in up())
            // $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
