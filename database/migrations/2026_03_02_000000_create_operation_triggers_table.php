<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_triggers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('tags')->nullable();
            $table->json('rule');
            $table->json('schedule')->nullable();
            $table->json('actions');
            $table->json('cooldown')->nullable();
            $table->json('debounce')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_fired_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('versions')->nullable();
            $table->json('audit')->nullable();
            $table->json('executions')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_triggers');
    }
};
