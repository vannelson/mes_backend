<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->longText('wod_ref')->nullable()->after('template');
        });
    }

    public function down(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->dropColumn('wod_ref');
        });
    }
};
