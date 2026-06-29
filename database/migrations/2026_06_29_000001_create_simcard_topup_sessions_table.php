<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simcard_topup_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('simcard_id')->index();
            $table->uuid('action_link_id')->nullable()->index();
            $table->string('package_code', 128)->index();
            $table->string('package_name', 180)->nullable();
            $table->unsignedBigInteger('data_bytes')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 32)->default('PENDING_PAYMENT')->index();
            $table->string('idempotency_key', 128)->unique();
            $table->uuid('commerce_order_id')->nullable()->index();
            $table->uuid('commerce_order_item_id')->nullable();
            $table->string('supplier_reference', 191)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['simcard_id', 'status']);
            $table->index(['commerce_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simcard_topup_sessions');
    }
};
