<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('work_order_no');
            $table->date('date');
            $table->date('posted_date');
            $table->date('document_date');
            $table->date('due_date');
            $table->date('date_printed')->nullable();
            $table->string('document_type')->nullable();
            $table->string('printed_by')->nullable();
            $table->string('qr_code')->nullable();
            $table->string('size')->nullable();
            $table->string('websize')->nullable();
            $table->string('material')->nullable();
            $table->string('colours')->nullable();
            $table->string('butt')->nullable();
            $table->string('interval')->nullable();
            $table->string('radius')->nullable();
            $table->string('material_not_number')->nullable();
            $table->string('operator_code')->nullable();
            $table->integer('number_of_used_up')->nullable();
            $table->integer('number_of_process')->nullable();
            $table->text('meta_remarks')->nullable();
            $table->text('metadata')->nullable(); // store JSON payload
            $table->timestamps();

            $table->unique('work_order_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
