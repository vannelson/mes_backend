<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_checklists', function (Blueprint $table) {
            $table->unique('wd_part_no');
        });
    }

    public function down(): void
    {
        Schema::table('packing_checklists', function (Blueprint $table) {
            $table->dropUnique(['wd_part_no']);
        });
    }
};
