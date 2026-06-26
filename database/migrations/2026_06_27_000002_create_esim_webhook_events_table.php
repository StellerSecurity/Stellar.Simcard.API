<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esim_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('notify_type', 50);
            $table->string('idempotency_key', 64)->unique();
            $table->uuid('simcard_id')->nullable();

            // Provider identifiers are sensitive. Store only HMAC hashes and safe suffixes.
            $table->string('external_order_id_hash', 100)->nullable();
            $table->string('transaction_id_hash', 100)->nullable();
            $table->string('transaction_id_last4', 4)->nullable();
            $table->string('iccid_hash', 100)->nullable();
            $table->string('iccid_last4', 4)->nullable();

            $table->string('status', 30)->default('received');
            $table->json('payload_redacted');
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->index(['provider', 'notify_type']);
            $table->index('simcard_id');
            $table->index('iccid_hash');
            $table->index('external_order_id_hash');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esim_webhook_events');
    }
};
