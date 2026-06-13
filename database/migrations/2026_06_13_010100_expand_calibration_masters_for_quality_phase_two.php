<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calibration_masters', function (Blueprint $table) {
            $table->string('supplier_vendor', 255)->nullable()->after('reference_no');
            $table->date('purchase_date')->nullable()->after('supplier_vendor');
            $table->string('calibration_type', 40)->nullable()->after('purchase_date');
            $table->unsignedSmallInteger('reminder_days')->default(30)->after('calibration_type');
            $table->boolean('is_active')->default(true)->after('reminder_days');
            $table->text('remarks')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('calibration_masters', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_vendor',
                'purchase_date',
                'calibration_type',
                'reminder_days',
                'is_active',
                'remarks',
            ]);
        });
    }
};
