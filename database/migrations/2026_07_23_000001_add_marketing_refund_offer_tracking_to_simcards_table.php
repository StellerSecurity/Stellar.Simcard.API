<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->timestamp('first_used_at')->nullable()->after('activated_at');
            $table->timestamp('marketing_refund_notification_attempted_at')->nullable()->after('first_used_at');
            $table->timestamp('marketing_refund_notification_queued_at')->nullable()->after('marketing_refund_notification_attempted_at');

            $table->index('first_used_at');
            $table->index(
                'marketing_refund_notification_queued_at',
                'simcards_marketing_refund_queued_at_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->dropIndex(['first_used_at']);
            $table->dropIndex('simcards_marketing_refund_queued_at_idx');
            $table->dropColumn([
                'first_used_at',
                'marketing_refund_notification_attempted_at',
                'marketing_refund_notification_queued_at',
            ]);
        });
    }
};
