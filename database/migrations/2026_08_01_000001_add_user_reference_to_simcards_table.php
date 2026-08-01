<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simcards', function (Blueprint $table): void {
            $table->string('user_ref', 80)->nullable()->after('user_id');
            $table->unsignedSmallInteger('user_ref_version')->nullable()->after('user_ref');
            $table->timestamp('user_linked_at')->nullable()->after('user_ref_version');
            $table->string('user_link_source', 40)->nullable()->after('user_linked_at');

            $table->index('user_ref', 'simcards_user_ref_index');
        });
    }

    public function down(): void
    {
        Schema::table('simcards', function (Blueprint $table): void {
            $table->dropIndex('simcards_user_ref_index');
            $table->dropColumn([
                'user_ref',
                'user_ref_version',
                'user_linked_at',
                'user_link_source',
            ]);
        });
    }
};
