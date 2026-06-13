<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 120)->nullable()->index();
            $table->string('supplier_name', 255)->index();
            $table->string('status', 40)->default('active')->index();
            $table->string('contact_person', 255)->nullable();
            $table->string('contact_number', 120)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('holiday_calendars', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date')->unique();
            $table->string('title', 255);
            $table->string('category', 80)->default('holiday');
            $table->boolean('is_working_day')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_type', 40)->index();
            $table->unsignedInteger('serial_no')->nullable()->index();
            $table->date('date_issue')->nullable()->index();
            $table->string('month_label', 40)->nullable()->index();
            $table->string('tracking_number', 120)->nullable()->index();
            $table->string('external_tracking_number', 120)->nullable()->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('customer_name', 255)->nullable()->index();
            $table->string('supplier_name', 255)->nullable()->index();
            $table->string('severity', 40)->nullable()->index();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->string('work_order_no', 120)->nullable()->index();
            $table->string('part_number', 120)->nullable()->index();
            $table->string('part_name', 255)->nullable();
            $table->string('material_code', 120)->nullable()->index();
            $table->string('material_name', 255)->nullable();
            $table->string('lot_number', 120)->nullable()->index();
            $table->text('problem_statement')->nullable();
            $table->string('reject_rate', 80)->nullable();
            $table->string('pic', 255)->nullable();
            $table->string('status', 60)->default('Open')->index();
            $table->date('closure_date')->nullable()->index();
            $table->date('target_acknowledgement_date')->nullable();
            $table->date('actual_acknowledgement_date')->nullable();
            $table->integer('kpi_days')->nullable();
            $table->date('target_closure_date')->nullable();
            $table->integer('actual_tat_days')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('immediate_action')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('preventive_action')->nullable();
            $table->text('remarks')->nullable();
            $table->text('comment')->nullable();
            $table->string('type_label', 120)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_follow_up_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_issue_id')->constrained('quality_issues')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence_no')->default(1);
            $table->string('label', 40)->nullable();
            $table->string('work_order_no', 120)->nullable()->index();
            $table->string('lot_number', 120)->nullable()->index();
            $table->string('result_status', 60)->nullable();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('eight_d_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_issue_id')->nullable()->constrained('quality_issues')->nullOnDelete();
            $table->string('report_number', 120)->unique();
            $table->string('tracking_number', 120)->nullable()->index();
            $table->date('date_issue')->nullable()->index();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_to_name', 255)->nullable();
            $table->string('severity', 40)->nullable()->index();
            $table->string('status', 60)->default('Open')->index();
            $table->dateTime('ack_due_at')->nullable();
            $table->dateTime('d3_due_at')->nullable();
            $table->dateTime('d4_due_at')->nullable();
            $table->dateTime('d5_due_at')->nullable();
            $table->dateTime('d8_due_at')->nullable();
            $table->dateTime('closure_due_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('eight_d_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eight_d_report_id')->constrained('eight_d_reports')->cascadeOnDelete();
            $table->string('step_code', 10)->index();
            $table->string('title', 255);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name', 255)->nullable();
            $table->boolean('is_completed')->default(false)->index();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->string('approval_status', 60)->default('Pending')->index();
            $table->longText('content')->nullable();
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 120)->index();
            $table->unsignedBigInteger('attachable_id')->index();
            $table->string('attachment_category', 80)->default('general')->index();
            $table->string('file_name', 255);
            $table->string('file_path', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('vpd_claims', function (Blueprint $table) {
            $table->id();
            $table->string('vpd_number', 120)->unique();
            $table->date('claim_date')->nullable()->index();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('vendor_name', 255)->nullable()->index();
            $table->string('material_code', 120)->nullable()->index();
            $table->string('description', 255)->nullable();
            $table->string('defect_type', 120)->nullable()->index();
            $table->string('sqm', 80)->nullable();
            $table->decimal('unit_price', 15, 4)->nullable();
            $table->decimal('amount', 15, 4)->nullable();
            $table->string('currency', 12)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();
            $table->decimal('total_sgd', 15, 4)->nullable();
            $table->date('car_completion_date')->nullable();
            $table->text('remarks')->nullable();
            $table->text('additional_reference')->nullable();
            $table->string('status', 60)->default('Open')->index();
            $table->text('notes')->nullable();
            $table->foreignId('quality_issue_id')->nullable()->constrained('quality_issues')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('aoi_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 40)->default('upload')->index();
            $table->string('source_file_name', 255)->nullable();
            $table->string('source_file_path', 255)->nullable();
            $table->string('import_status', 40)->default('pending')->index();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('imported_at')->nullable();
            $table->json('mapping')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('measurement_characteristic_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->string('part_number', 120)->nullable()->index();
            $table->string('program_name', 255)->nullable()->index();
            $table->string('characteristic_code', 120)->index();
            $table->string('characteristic_name', 255)->nullable();
            $table->decimal('nominal_value', 18, 6)->nullable();
            $table->decimal('target_value', 18, 6)->nullable();
            $table->decimal('lower_spec_limit', 18, 6)->nullable();
            $table->decimal('upper_spec_limit', 18, 6)->nullable();
            $table->decimal('lower_control_limit', 18, 6)->nullable();
            $table->decimal('upper_control_limit', 18, 6)->nullable();
            $table->string('units', 40)->nullable();
            $table->unsignedTinyInteger('decimal_precision')->default(3);
            $table->string('sampling_rule', 120)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('aoi_measurement_headers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aoi_import_batch_id')->nullable()->constrained('aoi_import_batches')->nullOnDelete();
            $table->dateTime('measurement_time')->nullable()->index();
            $table->string('result_status', 20)->nullable()->index();
            $table->string('lot_number', 120)->nullable()->index();
            $table->string('serial_counter', 120)->nullable()->index();
            $table->string('operator_name', 255)->nullable()->index();
            $table->string('roll_id', 120)->nullable()->index();
            $table->string('machine_number', 120)->nullable()->index();
            $table->string('computer_name', 255)->nullable();
            $table->string('program_name', 255)->nullable()->index();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->string('work_order_no', 120)->nullable()->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name', 255)->nullable()->index();
            $table->foreignId('machine_id')->nullable()->constrained('machines')->nullOnDelete();
            $table->foreignId('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('shift_name', 80)->nullable();
            $table->string('part_number', 120)->nullable()->index();
            $table->string('batch_number', 120)->nullable()->index();
            $table->string('error_code', 120)->nullable();
            $table->string('stop_reason', 255)->nullable();
            $table->boolean('is_reinspection')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('aoi_measurement_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aoi_measurement_header_id')->constrained('aoi_measurement_headers')->cascadeOnDelete();
            $table->foreignId('measurement_characteristic_spec_id')->nullable()->constrained('measurement_characteristic_specs')->nullOnDelete();
            $table->string('characteristic_code', 120)->index();
            $table->string('characteristic_name', 255)->nullable();
            $table->decimal('numeric_value', 18, 6)->nullable();
            $table->string('raw_value', 120)->nullable();
            $table->string('units', 40)->nullable();
            $table->decimal('nominal_value', 18, 6)->nullable();
            $table->decimal('lower_spec_limit', 18, 6)->nullable();
            $table->decimal('upper_spec_limit', 18, 6)->nullable();
            $table->boolean('is_out_of_spec')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aoi_measurement_details');
        Schema::dropIfExists('aoi_measurement_headers');
        Schema::dropIfExists('measurement_characteristic_specs');
        Schema::dropIfExists('aoi_import_batches');
        Schema::dropIfExists('vpd_claims');
        Schema::dropIfExists('quality_attachments');
        Schema::dropIfExists('eight_d_steps');
        Schema::dropIfExists('eight_d_reports');
        Schema::dropIfExists('quality_follow_up_lots');
        Schema::dropIfExists('quality_issues');
        Schema::dropIfExists('holiday_calendars');
        Schema::dropIfExists('suppliers');
    }
};
