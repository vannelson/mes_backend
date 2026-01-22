<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_order_evidences', function (Blueprint $table) {
            $table->id();
            $table->string('work_order_no');
            $table->string('route_name');
            $table->string('image_path');
            $table->string('original_name')->nullable();
            $table->timestamps();

            $table->index('work_order_no');
            $table->index(['work_order_no', 'route_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_evidences');
    }
};
