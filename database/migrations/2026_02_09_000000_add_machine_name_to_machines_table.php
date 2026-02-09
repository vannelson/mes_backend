<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('machines')) {
            return;
        }

        $hadMachineName = Schema::hasColumn('machines', 'machine_name');

        if (! $hadMachineName) {
            Schema::table('machines', function (Blueprint $table) {
                $table->string('machine_name')->nullable()->after('production_area');
            });

            if (Schema::hasColumn('machines', 'machine_type')) {
                DB::table('machines')
                    ->whereNull('machine_name')
                    ->update([
                        'machine_name' => DB::raw('machine_type'),
                    ]);

                DB::table('machines')
                    ->whereNotNull('machine_type')
                    ->update([
                        'machine_type' => null,
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('machines')) {
            return;
        }

        if (! Schema::hasColumn('machines', 'machine_name')) {
            return;
        }

        if (Schema::hasColumn('machines', 'machine_type')) {
            DB::table('machines')
                ->whereNull('machine_type')
                ->update([
                    'machine_type' => DB::raw('machine_name'),
                ]);
        }

        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('machine_name');
        });
    }
};
