<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->text('email_enc')->nullable()->after('user_id');
            $table->string('email_hash', 100)->nullable()->index()->after('email_enc');
            $table->timestamp('email_opt_in_at')->nullable()->after('email_hash');
            $table->string('email_source', 50)->nullable()->after('email_opt_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table) {
            $table->dropIndex(['email_hash']);
            $table->dropColumn([
                'email_enc',
                'email_hash',
                'email_opt_in_at',
                'email_source',
            ]);
        });
    }
};
