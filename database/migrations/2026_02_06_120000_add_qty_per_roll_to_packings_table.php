<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packings', function (Blueprint $table) {
            $table->string('qty_per_roll', 255)->nullable()->after('qty_per_box');
        });
    }

    public function down(): void
    {
        Schema::table('packings', function (Blueprint $table) {
            $table->dropColumn('qty_per_roll');
        });
    }
};
