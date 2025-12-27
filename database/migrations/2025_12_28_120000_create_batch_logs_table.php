<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('batch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no', 100)->unique();
            $table->unsignedInteger('total_rows')->default(0);
            $table->string('operator', 120);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_logs');
    }
};
