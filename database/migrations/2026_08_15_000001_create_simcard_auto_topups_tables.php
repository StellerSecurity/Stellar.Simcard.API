<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simcard_auto_topups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('simcard_id')->unique();
            $table->uuid('parent_commerce_order_id')->index();
            $table->uuid('parent_commerce_order_item_id')->index();
            $table->unsignedSmallInteger('commerce_unit')->default(1);
            $table->boolean('enabled')->default(true)->index();
            $table->string('state', 32)->default('ARMED')->index();
            $table->unsignedTinyInteger('trigger_percent')->default(35);
            $table->unsignedBigInteger('preferred_data_bytes');
            $table->unsignedInteger('preferred_duration_days')->nullable();
            $table->unsignedInteger('cycle')->default(1);
            $table->unsignedBigInteger('last_trigger_total_bytes')->nullable();
            $table->unsignedBigInteger('last_trigger_remaining_bytes')->nullable();
            $table->unsignedBigInteger('last_trigger_order_usage')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_rearmed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'state']);
        });

        Schema::create('simcard_auto_topup_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('auto_topup_id')->index();
            $table->unsignedInteger('cycle');
            $table->string('attempt_key', 128)->unique();
            $table->string('status', 32)->default('CLAIMED')->index();
            $table->unsignedBigInteger('observed_total_bytes')->nullable();
            $table->unsignedBigInteger('observed_remaining_bytes')->nullable();
            $table->unsignedBigInteger('observed_order_usage')->nullable();
            $table->decimal('observed_remaining_percent', 6, 2)->nullable();
            $table->uuid('topup_session_id')->nullable()->index();
            $table->uuid('commerce_order_id')->nullable()->index();
            $table->string('stripe_payment_intent_id', 191)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('payment_requested_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->unique(['auto_topup_id', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simcard_auto_topup_attempts');
        Schema::dropIfExists('simcard_auto_topups');
    }
};
