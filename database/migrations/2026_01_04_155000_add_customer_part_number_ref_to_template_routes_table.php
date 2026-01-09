<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->longText('customer_part_number_ref')->nullable()->after('wod_ref');
        });
    }

    public function down(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->dropColumn('customer_part_number_ref');
        });
    }
};
