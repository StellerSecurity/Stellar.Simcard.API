<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('simcard_support_replacements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('old_simcard_id')->index();
            $table->uuid('new_simcard_id')->nullable()->index();
            $table->string('idempotency_key', 191)->unique();
            $table->longText('new_plan_id_enc');
            $table->string('status', 32)->default('prepared')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('cancelled_old_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('old_simcard_id')->references('id')->on('simcards')->cascadeOnDelete();
            $table->foreign('new_simcard_id')->references('id')->on('simcards')->nullOnDelete();
            $table->unique('old_simcard_id', 'simcard_support_replacements_one_per_old');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simcard_support_replacements');
    }
};
