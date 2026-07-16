<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_logs', function (Blueprint $table): void {
            if (!Schema::hasColumn('batch_logs', 'inserted_rows')) {
                $table->unsignedInteger('inserted_rows')->default(0)->after('total_rows');
            }
            if (!Schema::hasColumn('batch_logs', 'updated_rows')) {
                $table->unsignedInteger('updated_rows')->default(0)->after('inserted_rows');
            }
            if (!Schema::hasColumn('batch_logs', 'skipped_rows')) {
                $table->unsignedInteger('skipped_rows')->default(0)->after('updated_rows');
            }
            if (!Schema::hasColumn('batch_logs', 'failed_rows')) {
                $table->unsignedInteger('failed_rows')->default(0)->after('skipped_rows');
            }
        });
    }

    public function down(): void
    {
        Schema::table('batch_logs', function (Blueprint $table): void {
            foreach (['failed_rows', 'skipped_rows', 'updated_rows', 'inserted_rows'] as $column) {
                if (Schema::hasColumn('batch_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
