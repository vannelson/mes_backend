<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('id');
            $table->unsignedBigInteger('template_route_id')->nullable()->after('customer_id');

            // optional FK constraints if you want:
            // $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            // $table->foreign('template_route_id')->references('id')->on('template_routes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // if you created FKs, drop them first:
            // $table->dropForeign(['customer_id']);
            // $table->dropForeign(['template_route_id']);

            $table->dropColumn(['customer_id', 'template_route_id']);
        });
    }
};
