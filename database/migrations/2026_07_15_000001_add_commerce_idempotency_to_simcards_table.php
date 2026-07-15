<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->string('commerce_order_id', 64)->nullable()->after('user_id');
            $table->string('commerce_order_item_id', 64)->nullable()->after('commerce_order_id');
            $table->unsignedSmallInteger('commerce_unit')->nullable()->after('commerce_order_item_id');
            $table->string('idempotency_key', 128)->nullable()->after('commerce_unit');

            $table->index('commerce_order_id');
            $table->unique('idempotency_key', 'simcards_idempotency_key_unique');
            $table->unique(
                ['commerce_order_id', 'commerce_order_item_id', 'commerce_unit'],
                'simcards_commerce_order_item_unit_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->dropUnique('simcards_commerce_order_item_unit_unique');
            $table->dropUnique('simcards_idempotency_key_unique');
            $table->dropIndex(['commerce_order_id']);
            $table->dropColumn([
                'commerce_order_id',
                'commerce_order_item_id',
                'commerce_unit',
                'idempotency_key',
            ]);
        });
    }
};
