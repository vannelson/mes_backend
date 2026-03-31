<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_change_controls', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name')->nullable();
            $table->text('address')->nullable();
            $table->string('tel_fax')->nullable();
            $table->string('status', 100)->default('draft');
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamp('implemented_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('supplier_name');
            $table->index('status');
            $table->index('current_step');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_change_controls');
    }
};

