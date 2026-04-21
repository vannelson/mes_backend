<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diecut_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diecut_profile_id')->nullable()->constrained('diecut_profiles')->nullOnDelete();
            $table->string('tool_code', 180)->index();
            $table->string('normalized_tool_code', 200)->index();
            $table->string('base_normalized_tool_code', 200)->nullable();
            $table->decimal('cavity', 12, 3)->nullable();
            $table->decimal('tool_life_pcs', 18, 3)->nullable();
            $table->decimal('tool_life_press', 18, 3)->nullable();
            $table->string('status', 50)->nullable()->index();
            $table->boolean('is_active')->default(false)->index();
            $table->date('received_date')->nullable();
            $table->date('start_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('source_sheet', 255)->nullable();
            $table->string('source_batch', 100)->nullable()->index();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('base_normalized_tool_code', 'diecut_tools_base_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diecut_tools');
    }
};
