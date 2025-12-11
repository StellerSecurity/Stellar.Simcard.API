<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('simcards', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Only the HMAC hash of plan_id is stored. Never the real plan_id.
            $table->string('plan_id_hash', 64)->unique();

            // Provider + package metadata
            $table->string('provider', 50);
            $table->string('package_code', 100);

            // Encrypted provider identifiers (AES-256-GCM)
            $table->text('external_order_id_enc');
            $table->text('iccid_enc')->nullable();

            // State and ownership
            $table->string('state', 30)->default('pending');
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
