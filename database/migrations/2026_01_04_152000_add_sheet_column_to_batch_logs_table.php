<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_logs', function (Blueprint $table) {
            $table->string('sheet', 255)->nullable()->after('operator');
        });
    }

    public function down(): void
    {
        Schema::table('batch_logs', function (Blueprint $table) {
            $table->dropColumn('sheet');
        });
    }
};
