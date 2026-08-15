<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcard_auto_topup_attempts', function (Blueprint $table) {
            $table->timestamp('notification_attempted_at')->nullable()->after('fulfilled_at');
            $table->timestamp('notification_sent_at')->nullable()->index()->after('notification_attempted_at');
            $table->text('notification_failure_reason')->nullable()->after('notification_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('simcard_auto_topup_attempts', function (Blueprint $table) {
            $table->dropIndex(['notification_sent_at']);
            $table->dropColumn([
                'notification_attempted_at',
                'notification_sent_at',
                'notification_failure_reason',
            ]);
        });
    }
};
