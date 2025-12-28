<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            # Use raw SQL to avoid requiring doctrine/dbal for column alteration
            DB::statement('ALTER TABLE customers MODIFY customer_name VARCHAR(200) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            # Backfill nulls to a safe value before restoring NOT NULL constraint
            DB::statement('UPDATE customers SET customer_name = customer_code WHERE customer_name IS NULL');
            DB::statement('ALTER TABLE customers MODIFY customer_name VARCHAR(200) NOT NULL');
        }
    }
};
