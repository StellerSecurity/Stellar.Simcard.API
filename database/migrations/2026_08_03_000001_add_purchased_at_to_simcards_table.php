<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Retained only for migration-ledger compatibility with the short-lived
     * v2.40 release. Fresh deployments must not create a purchased_at column.
     */
    public function up(): void
    {
        // Intentionally empty. purchased_on is the single purchase timestamp.
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
