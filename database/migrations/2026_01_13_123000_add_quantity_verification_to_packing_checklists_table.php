<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_checklists', function (Blueprint $table) {
            $table->json('quantity_verification')->nullable()->after('double_bag_checklist');
        });
    }

    public function down(): void
    {
        Schema::table('packing_checklists', function (Blueprint $table) {
            $table->dropColumn('quantity_verification');
        });
    }
};
