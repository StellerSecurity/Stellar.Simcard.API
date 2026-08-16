<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcard_auto_topup_attempts', function (Blueprint $table) {
            $table->timestamp('sms_attempted_at')->nullable()->after('notification_failure_reason');
            $table->timestamp('sms_sent_at')->nullable()->index()->after('sms_attempted_at');
            $table->text('sms_failure_reason')->nullable()->after('sms_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('simcard_auto_topup_attempts', function (Blueprint $table) {
            $table->dropIndex(['sms_sent_at']);
            $table->dropColumn([
                'sms_attempted_at',
                'sms_sent_at',
                'sms_failure_reason',
            ]);
        });
    }
};
