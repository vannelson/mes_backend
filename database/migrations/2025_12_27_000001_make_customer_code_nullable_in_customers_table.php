<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            DB::statement('ALTER TABLE customers MODIFY customer_code VARCHAR(50) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            // Backfill nulls before restoring NOT NULL
            DB::statement("UPDATE customers SET customer_code = CONCAT('AUTO-', id) WHERE customer_code IS NULL");
            DB::statement('ALTER TABLE customers MODIFY customer_code VARCHAR(50) NOT NULL');
        }
    }
};
