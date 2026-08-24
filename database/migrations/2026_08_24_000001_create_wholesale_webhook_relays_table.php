<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_webhook_relays', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->default('esimaccess');
            $table->longText('payload_encrypted');
            $table->string('content_type', 120)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->index(['status', 'next_attempt_at'], 'wholesale_webhook_relay_retry_idx');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_webhook_relays');
    }
};
