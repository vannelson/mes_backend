<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_setup_inspection_checklist_records', function (Blueprint $table) {
            $table->id();

            $table->string('work_order_no')->index('idx_wo_sic_work_order_no');
            $table->string('route_key')->index('idx_wo_sic_route_key');
            $table->string('route_name')->nullable();

            $table->unsignedBigInteger('machine_id')->nullable()->index('idx_wo_sic_machine_id');
            $table->string('machine_key')->index('idx_wo_sic_machine_key');
            $table->string('machine_type');
            $table->string('machine_no')->nullable();
            $table->string('machine_label')->nullable();

            $table->date('record_date')->index('idx_wo_sic_record_date');
            $table->unsignedSmallInteger('slot')->default(1);

            $table->json('entries')->nullable();

            $table->unsignedTinyInteger('save_count')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->string('locked_reason')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable()->index('idx_wo_sic_locked_by');
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('unlocked_by')->nullable()->index('idx_wo_sic_unlocked_by');
            $table->timestamp('unlocked_at')->nullable();

            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable()->index('idx_wo_sic_approved_by');
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['work_order_no', 'route_key', 'machine_key', 'record_date', 'slot'],
                'uq_setup_inspection_sheet'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_setup_inspection_checklist_records');
    }
};
