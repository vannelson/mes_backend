<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('virtual_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('share_token', 64)->unique();
            $table->enum('orientation', ['landscape', 'portrait'])->default('landscape');
            $table->string('aspect_ratio', 10)->default('16:9');
            $table->string('timezone', 50)->default('UTC');
            $table->integer('refresh_interval')->default(300); // seconds
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // transitions, looping, etc.
            $table->timestamps();

            $table->index('user_id');
            $table->index('share_token');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('virtual_screens');
    }
};
