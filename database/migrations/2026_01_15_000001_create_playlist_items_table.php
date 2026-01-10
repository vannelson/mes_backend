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
        Schema::create('playlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_screen_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['url', 'widget', 'image', 'pdf'])->default('url');
            $table->json('content'); // stores URL, widget config, or file reference
            $table->integer('duration')->default(10); // seconds
            $table->integer('order')->default(0);
            $table->dateTime('schedule_start')->nullable();
            $table->dateTime('schedule_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('virtual_screen_id');
            $table->index(['virtual_screen_id', 'order']);
            $table->index('is_active');
            $table->index(['schedule_start', 'schedule_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
    }
};
