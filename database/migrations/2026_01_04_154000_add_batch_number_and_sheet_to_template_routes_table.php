<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->string('batch_number', 100)->nullable()->after('wod_ref');
            $table->string('sheet', 255)->nullable()->after('batch_number');
        });
    }

    public function down(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->dropColumn(['batch_number', 'sheet']);
        });
    }
};
