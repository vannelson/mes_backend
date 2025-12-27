<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'priority_type',
                'date',
                'posted_date',
                'document_date',
                'due_date',
                'date_printed',
                'document_type',
                'printed_by',
                'size',
                'websize',
                'material',
                'colours',
                'butt',
                'interval',
                'radius',
                'material_not_number',
                'operator_code',
                'number_of_used_up',
                'number_of_process',
                'meta_remarks',
            ]);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->boolean('selected')->default(false)->after('template_route_id');
            $table->string('mes_batch_no', 100)->nullable()->after('selected');
            $table->string('customer_code', 100)->nullable()->after('customer_id');
            $table->string('customer_name', 200)->nullable()->after('customer_code');
            $table->string('material_1_code', 100)->nullable()->after('mes_batch_no');
            $table->string('material_2_code', 100)->nullable()->after('material_1_code');
            $table->string('material_3_code', 100)->nullable()->after('material_2_code');
            $table->string('material_4_code', 100)->nullable()->after('material_3_code');
            $table->string('customer_part_number', 120)->nullable()->after('material_4_code');
            $table->date('production_due_date')->nullable()->after('customer_part_number');
            $table->string('quantity_to_produce', 100)->nullable()->after('production_due_date');
            $table->string('quantity_produced', 100)->nullable()->after('quantity_to_produce');
            $table->string('forecast_quantity', 100)->nullable()->after('quantity_produced');
            $table->string('die_cut', 120)->nullable()->after('forecast_quantity');
            $table->text('internal_remark')->nullable()->after('die_cut');
            $table->date('requested_delivery_date')->nullable()->after('internal_remark');
            $table->string('no_of_colours', 100)->nullable()->after('requested_delivery_date');
            $table->string('sales_person_code', 100)->nullable()->after('no_of_colours');
            $table->date('order_date')->nullable()->after('sales_person_code');
            $table->date('production_date_completed')->nullable()->after('order_date');
            $table->string('production_qty_completed', 100)->nullable()->after('production_date_completed');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'selected',
                'mes_batch_no',
                'customer_code',
                'customer_name',
                'material_1_code',
                'material_2_code',
                'material_3_code',
                'material_4_code',
                'customer_part_number',
                'production_due_date',
                'quantity_to_produce',
                'quantity_produced',
                'forecast_quantity',
                'die_cut',
                'internal_remark',
                'requested_delivery_date',
                'no_of_colours',
                'sales_person_code',
                'order_date',
                'production_date_completed',
                'production_qty_completed',
            ]);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('priority_type')->nullable()->after('work_order_no');
            $table->date('date')->after('priority_type');
            $table->date('posted_date')->after('date');
            $table->date('document_date')->after('posted_date');
            $table->date('due_date')->after('document_date');
            $table->date('date_printed')->nullable()->after('due_date');
            $table->string('document_type')->nullable()->after('date_printed');
            $table->string('printed_by')->nullable()->after('document_type');
            $table->string('size')->nullable()->after('printed_by');
            $table->string('websize')->nullable()->after('size');
            $table->string('material')->nullable()->after('websize');
            $table->string('colours')->nullable()->after('material');
            $table->string('butt')->nullable()->after('colours');
            $table->string('interval')->nullable()->after('butt');
            $table->string('radius')->nullable()->after('interval');
            $table->string('material_not_number')->nullable()->after('radius');
            $table->string('operator_code')->nullable()->after('material_not_number');
            $table->integer('number_of_used_up')->nullable()->after('operator_code');
            $table->integer('number_of_process')->nullable()->after('number_of_used_up');
            $table->text('meta_remarks')->nullable()->after('number_of_process');
        });
    }
};
