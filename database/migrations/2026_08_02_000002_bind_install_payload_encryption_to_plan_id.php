<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('simcards', 'install_payload_crypto_version')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->unsignedTinyInteger('install_payload_crypto_version')
                    ->nullable()
                    ->after('install_payload_enc');
            });
        }

        // v2.1 used a service-wide recoverable key. Installation credentials can
        // be fetched again from the provider, so discard those ciphertexts rather
        // than retaining data that is decryptable without the private plan_id.
        DB::table('simcards')
            ->whereNotNull('install_payload_enc')
            ->update([
                'install_payload_enc' => null,
                'install_payload_crypto_version' => null,
                'install_payload_captured_at' => null,
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('simcards', 'install_payload_crypto_version')) {
            Schema::table('simcards', function (Blueprint $table): void {
                $table->dropColumn('install_payload_crypto_version');
            });
        }
    }
};
