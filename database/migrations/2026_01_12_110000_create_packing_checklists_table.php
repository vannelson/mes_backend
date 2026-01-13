<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_checklists', function (Blueprint $table) {
            $table->id();
            $table->string('work_order_no', 100);
            $table->string('wd_part_no', 100);
            $table->json('double_bag_checklist')->nullable();
            $table->json('quantity_verification')->nullable();
            $table->boolean('roll_per_box')->default(false);
            $table->string('ul_label_image', 255)->nullable();
            $table->string('product_image', 255)->nullable();
            $table->string('core_image', 255)->nullable();
            $table->json('carton_label_data')->nullable();
            $table->string('carton_label_image', 255)->nullable();
            $table->text('no_of_cartons')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_checklists');
    }
};
