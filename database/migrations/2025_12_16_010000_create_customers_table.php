<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // CORE CUSTOMER INFORMATION
            $table->string('customer_code', 50)->unique();
            $table->string('customer_name', 200);
            $table->enum('customer_type', ['Individual', 'Company'])->default('Company');
            $table->enum('status', ['Active', 'Inactive'])->default('Active');

            // LOCATION & ADDRESS
            $table->string('country', 100)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('postal_code', 30)->nullable();

            // CONTACT DETAILS
            $table->string('contact_person', 150)->nullable();
            $table->string('contact_number', 50)->nullable();
            $table->string('alt_contact_number', 50)->nullable();
            $table->string('email', 150)->nullable();

            // BUSINESS & LEGAL DETAILS
            $table->string('business_name', 200)->nullable();
            $table->string('business_registration_no', 100)->nullable();
            $table->string('tax_id', 100)->nullable();
            $table->string('industry', 120)->nullable();

            // FINANCIAL & BILLING
            $table->string('currency', 10)->default('PHP');
            $table->string('payment_terms', 100)->nullable();
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->enum('tax_type', ['VAT', 'Non-VAT'])->nullable();
            $table->string('billing_address', 255)->nullable();
            $table->string('shipping_address', 255)->nullable();

            // OPTIONAL ERP-LEVEL
            $table->string('customer_group', 120)->nullable();
            $table->string('sales_representative', 150)->nullable();
            $table->string('pricing_tier', 50)->nullable();
            $table->decimal('discount_rate', 5, 2)->default(0);

            // SYSTEM & AUDIT
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Helpful indexes
            $table->index(['customer_name']);
            $table->index(['country', 'city']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
