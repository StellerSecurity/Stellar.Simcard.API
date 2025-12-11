<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('simcards', function (Blueprint $table) {
            // Primary key as UUID
            $table->uuid('id')->primary();

            // Public identifier used by APIs / frontend
            $table->string('plan_id', 32)->unique();

            // Provider info
            $table->string('provider', 50); // e.g. "esimaccess"
            $table->string('package_code', 100);

            // Sensitive fields - will be encrypted at model level
            $table->text('external_order_id'); // encrypted cast
            $table->text('iccid')->nullable(); // encrypted cast

            // State and meta
            $table->string('state', 30)->default('pending'); // pending, active, failed, expired
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('account_ref', 191)->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simcards');
    }
};
