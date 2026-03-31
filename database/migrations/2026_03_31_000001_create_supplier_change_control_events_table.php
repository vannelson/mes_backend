<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_change_control_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_change_control_id');
            $table->string('action', 100);
            $table->unsignedTinyInteger('step')->nullable();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('supplier_change_control_id', 'scc_events_scc_id_fk')
                ->references('id')
                ->on('supplier_change_controls')
                ->cascadeOnDelete();
            $table->index('action');
            $table->index('step');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_change_control_events');
    }
};
