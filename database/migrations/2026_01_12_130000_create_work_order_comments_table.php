<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('work_order_comments')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('work_order_comments')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('comment');
            $table->string('visibility', 20)->default('internal');
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->string('status', 30)->nullable();
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'thread_id']);
            $table->index(['work_order_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_comments');
    }
};
