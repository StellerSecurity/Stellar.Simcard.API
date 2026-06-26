<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simcard_action_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('simcard_id');
            $table->string('action', 40);
            $table->string('token_hash', 100)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->json('metadata_redacted')->nullable();
            $table->timestamps();

            $table->index(['simcard_id', 'action']);
            $table->index('expires_at');
            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simcard_action_links');
    }
};
