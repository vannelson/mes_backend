<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('template_routes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('template');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_routes');
    }
};
