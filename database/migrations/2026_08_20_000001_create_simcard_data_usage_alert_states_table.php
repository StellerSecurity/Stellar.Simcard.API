<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->index(['provider', 'esim_status'], 'simcards_provider_esim_status_idx');
        });

        Schema::create('simcard_data_usage_alert_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('simcard_id');
            $table->unsignedTinyInteger('threshold_percent')->default(50);
            $table->string('state', 24)->default('ARMED')->index();
            $table->unsignedInteger('cycle')->default(1);

            $table->timestamp('last_checked_at')->nullable()->index();
            $table->text('last_check_failure_reason')->nullable();
            $table->unsignedBigInteger('last_observed_total_bytes')->nullable();
            $table->unsignedBigInteger('last_observed_remaining_bytes')->nullable();
            $table->unsignedBigInteger('last_observed_order_usage')->nullable();
            $table->decimal('last_observed_remaining_percent', 6, 2)->nullable();

            $table->unsignedBigInteger('trigger_total_bytes')->nullable();
            $table->unsignedBigInteger('trigger_remaining_bytes')->nullable();
            $table->unsignedBigInteger('trigger_order_usage')->nullable();
            $table->decimal('trigger_remaining_percent', 6, 2)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('last_rearmed_at')->nullable();

            $table->string('sms_status', 24)->nullable();
            $table->timestamp('sms_attempted_at')->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->text('sms_failure_reason')->nullable();

            $table->string('email_status', 24)->nullable();
            $table->timestamp('email_attempted_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->text('email_failure_reason')->nullable();

            $table->timestamps();

            $table->unique(['simcard_id', 'threshold_percent'], 'simcard_usage_alert_state_threshold_uq');
            $table->index(['threshold_percent', 'last_checked_at'], 'simcard_usage_alert_poll_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simcard_data_usage_alert_states');

        Schema::table('simcards', function (Blueprint $table) {
            $table->dropIndex('simcards_provider_esim_status_idx');
        });
    }
};
