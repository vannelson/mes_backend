<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('customer_code', 50)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            DB::table('customers')->whereNull('customer_code')->update(['customer_code' => DB::raw("'AUTO-' || id")]);
            Schema::table('customers', function (Blueprint $table) {
                $table->string('customer_code', 50)->nullable(false)->change();
            });
        }
    }
};
