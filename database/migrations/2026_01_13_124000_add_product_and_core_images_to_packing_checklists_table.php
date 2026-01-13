<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_checklists', function (Blueprint $table) {
            $table->string('product_image', 255)->nullable()->after('ul_label_image');
            $table->string('core_image', 255)->nullable()->after('product_image');
        });
    }

    public function down(): void
    {
        Schema::table('packing_checklists', function (Blueprint $table) {
            $table->dropColumn(['product_image', 'core_image']);
        });
    }
};
