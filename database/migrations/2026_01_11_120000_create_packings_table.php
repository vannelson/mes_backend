<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packings', function (Blueprint $table) {
            $table->id();
            $table->string('wd_part_no')->nullable();
            $table->string('material')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->string('design')->nullable();
            $table->string('shipping_location')->nullable();
            $table->string('customer_code')->nullable();
            $table->string('box_size')->nullable();
            $table->string('qty_per_box')->nullable();
            $table->string('rolls_per_box')->nullable();
            $table->string('core_label_left')->nullable();
            $table->string('core_label_right')->nullable();
            $table->string('hm_no')->nullable();
            $table->string('ul_label_no')->nullable();
            $table->string('cas')->nullable();
            $table->boolean('important')->default(false);
            $table->string('code_1')->nullable();
            $table->string('underline_code')->nullable();
            $table->string('colour_code')->nullable();
            $table->string('wd_revision')->nullable();
            $table->string('revised_by_pic')->nullable();
            $table->date('date_of_revised')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packings');
    }
};
