<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->string('external_order_id_hash', 100)->nullable()->after('external_order_id_enc');

            // ICCID is sensitive. Store only encrypted value for provider operations,
            // HMAC hash for lookup/audit, and last4 for safe support display.
            $table->text('iccid_enc')->nullable()->after('external_order_id_hash');
            $table->string('iccid_hash', 100)->nullable()->after('iccid_enc');
            $table->string('iccid_last4', 4)->nullable()->after('iccid_hash');

            $table->string('esim_status', 50)->nullable()->after('state');
            $table->string('smdp_status', 50)->nullable()->after('esim_status');
            $table->string('data_status', 30)->nullable()->after('smdp_status');
            $table->string('validity_status', 30)->nullable()->after('data_status');
            $table->unsignedBigInteger('total_volume')->nullable()->after('validity_status');
            $table->unsignedBigInteger('order_usage')->nullable()->after('total_volume');
            $table->unsignedBigInteger('remaining_volume')->nullable()->after('order_usage');
            $table->unsignedInteger('remaining_validity')->nullable()->after('remaining_volume');
            $table->timestamp('expires_at')->nullable()->after('remaining_validity');
            $table->timestamp('activated_at')->nullable()->after('expires_at');
            $table->timestamp('last_webhook_at')->nullable()->after('activated_at');

            $table->unique('external_order_id_hash', 'simcards_external_order_id_hash_uq');
            $table->index('iccid_hash');
            $table->index('last_webhook_at');
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->dropUnique('simcards_external_order_id_hash_uq');
            $table->dropIndex(['iccid_hash']);
            $table->dropIndex(['last_webhook_at']);

            $table->dropColumn([
                'external_order_id_hash',
                'iccid_enc',
                'iccid_hash',
                'iccid_last4',
                'esim_status',
                'smdp_status',
                'data_status',
                'validity_status',
                'total_volume',
                'order_usage',
                'remaining_volume',
                'remaining_validity',
                'expires_at',
                'activated_at',
                'last_webhook_at',
            ]);
        });
    }
};
