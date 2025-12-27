<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('quantity_to_produce', 100)->nullable()->change();
            $table->string('quantity_produced', 100)->nullable()->change();
            $table->string('forecast_quantity', 100)->nullable()->change();
            $table->string('no_of_colours', 100)->nullable()->change();
            $table->string('production_qty_completed', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedInteger('quantity_to_produce')->nullable()->change();
            $table->unsignedInteger('quantity_produced')->nullable()->change();
            $table->unsignedInteger('forecast_quantity')->nullable()->change();
            $table->unsignedInteger('no_of_colours')->nullable()->change();
            $table->unsignedInteger('production_qty_completed')->nullable()->change();
        });
    }
};
