<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diecut_profile_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diecut_profile_id')->constrained('diecut_profiles')->cascadeOnDelete();
            $table->string('alias_code', 150);
            $table->string('normalized_alias', 180)->unique();
            $table->string('base_normalized_alias', 180)->nullable()->index();
            $table->string('alias_type', 50)->nullable()->index();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diecut_profile_aliases');
    }
};
