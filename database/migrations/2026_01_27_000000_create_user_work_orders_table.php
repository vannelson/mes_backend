<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('route_key', 150);
            $table->string('route_code', 50)->nullable();
            $table->string('route_name', 120)->nullable();
            $table->unsignedInteger('order_seq')->nullable();
            $table->string('assigned_qty', 60)->nullable();
            $table->timestamps();

            $table->unique(['work_order_id', 'user_id', 'route_key']);
            $table->index(['user_id', 'work_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_work_orders');
    }
};
