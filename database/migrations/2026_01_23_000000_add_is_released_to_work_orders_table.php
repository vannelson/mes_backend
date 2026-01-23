<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('work_orders', 'is_released')) {
                $table->boolean('is_released')->default(false)->after('metadata');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('work_orders', 'is_released')) {
                $table->dropColumn('is_released');
            }
        });
    }
};
