<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diecut_tool_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diecut_tool_id')->nullable()->constrained('diecut_tools')->nullOnDelete();
            $table->foreignId('diecut_profile_id')->nullable()->constrained('diecut_profiles')->nullOnDelete();
            $table->date('usage_date')->nullable()->index();
            $table->string('machine_no', 120)->nullable()->index();
            $table->string('customer_code', 120)->nullable()->index();
            $table->string('work_order_no', 120)->nullable()->index();
            $table->string('customer_part_number', 255)->nullable()->index();
            $table->decimal('cavity', 12, 3)->nullable();
            $table->decimal('printed_qty', 18, 3)->nullable();
            $table->decimal('number_of_press', 18, 3)->nullable();
            $table->string('source_sheet', 255)->nullable();
            $table->string('source_batch', 100)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diecut_tool_usages');
    }
};
