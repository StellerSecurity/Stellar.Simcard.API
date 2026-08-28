<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcard_auto_topup_attempts', function (Blueprint $table): void {
            $table->text('payment_recovery_url_enc')->nullable()->after('sms_failure_reason');
            $table->timestamp('payment_recovery_expires_at')->nullable()->after('payment_recovery_url_enc');
            $table->timestamp('payment_failed_at')->nullable()->index()->after('payment_recovery_expires_at');
            $table->timestamp('payment_recovered_at')->nullable()->index()->after('payment_failed_at');
            $table->timestamp('payment_failure_notification_attempted_at')->nullable()->after('payment_recovered_at');
            $table->timestamp('payment_failure_notification_sent_at')->nullable()->index('sat_attempts_fail_email_sent_idx')->after('payment_failure_notification_attempted_at');
            $table->text('payment_failure_notification_failure_reason')->nullable()->after('payment_failure_notification_sent_at');
            $table->timestamp('payment_failure_sms_attempted_at')->nullable()->after('payment_failure_notification_failure_reason');
            $table->timestamp('payment_failure_sms_sent_at')->nullable()->index('sat_attempts_fail_sms_sent_idx')->after('payment_failure_sms_attempted_at');
            $table->text('payment_failure_sms_failure_reason')->nullable()->after('payment_failure_sms_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('simcard_auto_topup_attempts', function (Blueprint $table): void {
            $table->dropIndex('sat_attempts_fail_email_sent_idx');
            $table->dropIndex('sat_attempts_fail_sms_sent_idx');
            $table->dropIndex(['payment_failed_at']);
            $table->dropIndex(['payment_recovered_at']);
            $table->dropColumn([
                'payment_recovery_url_enc',
                'payment_recovery_expires_at',
                'payment_failed_at',
                'payment_recovered_at',
                'payment_failure_notification_attempted_at',
                'payment_failure_notification_sent_at',
                'payment_failure_notification_failure_reason',
                'payment_failure_sms_attempted_at',
                'payment_failure_sms_sent_at',
                'payment_failure_sms_failure_reason',
            ]);
        });
    }
};
