<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_logs', function (Blueprint $table) {
            $table->string('type', 50)->default('work_order')->after('batch_no');
        });
    }

    public function down(): void
    {
        Schema::table('batch_logs', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
