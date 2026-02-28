<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('work_orders', 'production_start_date')) {
                $table->date('production_start_date')->nullable()->after('order_date');
            }
            if (!Schema::hasColumn('work_orders', 'priority')) {
                $table->string('priority', 20)->nullable()->default('MEDIUM')->after('work_order_no');
            }
            if (!Schema::hasColumn('work_orders', 'is_starred')) {
                $table->boolean('is_starred')->default(false)->after('priority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'is_starred')) {
                $table->dropColumn('is_starred');
            }
            if (Schema::hasColumn('work_orders', 'priority')) {
                $table->dropColumn('priority');
            }
            if (Schema::hasColumn('work_orders', 'production_start_date')) {
                $table->dropColumn('production_start_date');
            }
        });
    }
};
