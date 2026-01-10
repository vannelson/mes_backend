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
        Schema::create('screen_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_screen_id')->constrained()->onDelete('cascade');
            $table->string('filename');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->bigInteger('size'); // bytes
            $table->string('path');
            $table->timestamps();

            $table->index('virtual_screen_id');
            $table->index('mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screen_media');
    }
};
