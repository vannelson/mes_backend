<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('status', 30)->default('backlog')->after('work_order_no');
            $table->timestamp('completed_at')->nullable()->after('production_date_completed');
            $table->index('status');
            $table->index('updated_at');
            $table->index('production_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['updated_at']);
            $table->dropIndex(['production_due_date']);
            $table->dropColumn(['status', 'completed_at']);
        });
    }
};
