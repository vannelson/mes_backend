<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function ($table) {
            $table->dropUnique('work_orders_work_order_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function ($table) {
            $table->unique('work_order_no');
        });
    }
};
