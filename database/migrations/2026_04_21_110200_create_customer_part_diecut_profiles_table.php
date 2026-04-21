<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_part_diecut_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diecut_profile_id')->constrained('diecut_profiles')->cascadeOnDelete();
            $table->string('customer_code', 120)->nullable()->index();
            $table->string('customer_part_number', 255);
            $table->string('normalized_customer_part_number', 280);
            $table->string('source_sheet', 255)->nullable();
            $table->string('source_batch', 100)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('normalized_customer_part_number', 'cust_part_diecut_norm_part_idx');
            $table->unique(['normalized_customer_part_number', 'diecut_profile_id'], 'cust_part_diecut_profile_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_part_diecut_profiles');
    }
};
