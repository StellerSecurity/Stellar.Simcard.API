<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('simcards', 'install_payload_enc')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->text('install_payload_enc')->nullable()->after('external_order_id_enc');
            });
        }

        if (! Schema::hasColumn('simcards', 'install_payload_captured_at')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->timestamp('install_payload_captured_at')->nullable()->after('install_payload_enc');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('simcards', 'install_payload_captured_at')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->dropColumn('install_payload_captured_at');
            });
        }

        if (Schema::hasColumn('simcards', 'install_payload_enc')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->dropColumn('install_payload_enc');
            });
        }
    }
};
