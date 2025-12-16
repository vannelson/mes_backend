<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('work_orders', 'template_route_id')) {
                $table->foreignId('template_route_id')
                    ->after('customer_id')
                    ->nullable()
                    ->constrained('template_routes')
                    ->nullOnDelete();
            }
        });

        Schema::dropIfExists('work_order_routes');
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'template_route_id')) {
                $table->dropConstrainedForeignId('template_route_id');
            }
        });
    }
};
