<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_work_orders', function (Blueprint $table) {
            $table->id();
            $table->text('work_order_no')->nullable();
            $table->text('work_order_line_no')->nullable();
            $table->text('wo_journal_line_no')->nullable();
            $table->text('add_date')->nullable();
            $table->text('add_user')->nullable();
            $table->text('add_time')->nullable();
            $table->text('material_batch_no')->nullable();
            $table->text('die_cut')->nullable();
            $table->text('process_no')->nullable();
            $table->text('posted')->nullable();
            $table->text('machine_code')->nullable();
            $table->text('machine_name')->nullable();
            $table->text('machine_type')->nullable();
            $table->text('staff_code')->nullable();
            $table->text('no_of_press')->nullable();
            $table->text('date_started')->nullable();
            $table->text('time_started')->nullable();
            $table->text('date_completed')->nullable();
            $table->text('time_completed')->nullable();
            $table->text('no_of_ups')->nullable();
            $table->text('printed_quantity')->nullable();
            $table->text('journal_type')->nullable();
            $table->text('item_code')->nullable();
            $table->text('quantity')->nullable();
            $table->text('qc_approved_quantity')->nullable();
            $table->text('rejected_quantity')->nullable();
            $table->text('roll')->nullable();
            $table->text('length')->nullable();
            $table->text('width')->nullable();
            $table->text('length_uom')->nullable();
            $table->text('width_uom')->nullable();
            $table->text('rm_quantity')->nullable();
            $table->text('material_code')->nullable();
            $table->text('variant_code')->nullable();
            $table->text('request_delivery_date')->nullable();
            $table->text('non_qc_record')->nullable();
            $table->text('link_to_line_no')->nullable();
            $table->text('related')->nullable();
            $table->text('expire_date')->nullable();
            $table->text('label_remark')->nullable();
            $table->text('customer_part_number')->nullable();
            $table->text('customer_code')->nullable();
            $table->text('ddmm')->nullable();
            $table->text('label_packing_quantity_1')->nullable();
            $table->text('label_quantity_1')->nullable();
            $table->text('label_packing_quantity_2')->nullable();
            $table->text('label_quantity_2')->nullable();
            $table->text('label_packing_quantity_3')->nullable();
            $table->text('label_quantity_3')->nullable();
            $table->text('uom_for_label_printing')->nullable();
            $table->text('qc_inspector')->nullable();
            $table->text('summarised_period')->nullable();
            $table->text('summarised')->nullable();
            $table->text('posted_work_order_no')->nullable();
            $table->text('colour')->nullable();
            $table->text('currency')->nullable();
            $table->text('ref_customer')->nullable();
            $table->text('group')->nullable();
            $table->text('po')->nullable();
            $table->text('source_doc_no')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_work_orders');
    }
};
