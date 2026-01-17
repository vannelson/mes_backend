<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('diecut_types', function (Blueprint $table) {
            $table->id();
            $table->string('document', 120);
            $table->string('code', 50);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['document', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diecut_types');
    }
};
