<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_triggers', function (Blueprint $table) {
            $table->json('flow')->nullable()->after('actions');
        });
    }

    public function down(): void
    {
        Schema::table('operation_triggers', function (Blueprint $table) {
            $table->dropColumn('flow');
        });
    }
};
