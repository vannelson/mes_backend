<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['customer_type', 'credit_limit', 'discount_rate', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('customer_type', ['Individual', 'Company'])->default('Company');
            $table->string('currency', 10)->default('PHP');
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->decimal('discount_rate', 5, 2)->default(0);
        });
    }
};
