<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->string('work_order_no')->nullable()->index();
            $table->string('route_key')->nullable()->index();
            $table->string('action', 120)->index();
            $table->string('context', 120)->nullable()->index();
            $table->string('entity_type', 80)->default('work_order')->index();
            $table->string('entity_id', 120)->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 80)->nullable()->index();
            $table->string('summary', 500)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'action']);
            $table->index(['work_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
