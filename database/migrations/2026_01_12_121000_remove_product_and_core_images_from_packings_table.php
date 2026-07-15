<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packings', function (Blueprint $table) {
            $columns = array_filter(['product_image', 'core_image'], fn($col) => Schema::hasColumn('packings', $col));
            if ($columns) {
                $table->dropColumn(array_values($columns));
            }
        });
    }

    public function down(): void
    {
        Schema::table('packings', function (Blueprint $table) {
            $table->string('product_image', 255)->nullable()->after('design');
            $table->string('core_image', 255)->nullable()->after('product_image');
        });
    }
};
