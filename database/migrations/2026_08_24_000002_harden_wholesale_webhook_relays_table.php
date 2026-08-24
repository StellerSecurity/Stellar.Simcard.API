<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_webhook_relays', function (Blueprint $table): void {
            $table->string('commerce_order_id', 64)->nullable()->after('content_type');
            $table->string('commerce_order_item_id', 64)->nullable()->after('commerce_order_id');
            $table->unsignedSmallInteger('commerce_unit')->nullable()->after('commerce_order_item_id');

            $table->index(
                ['commerce_order_id', 'commerce_order_item_id', 'commerce_unit'],
                'wholesale_webhook_relay_commerce_idx'
            );
            $table->index(['status', 'last_attempt_at'], 'wholesale_webhook_relay_stale_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_webhook_relays', function (Blueprint $table): void {
            $table->dropIndex('wholesale_webhook_relay_commerce_idx');
            $table->dropIndex('wholesale_webhook_relay_stale_idx');
            $table->dropColumn([
                'commerce_order_id',
                'commerce_order_item_id',
                'commerce_unit',
            ]);
        });
    }
};
