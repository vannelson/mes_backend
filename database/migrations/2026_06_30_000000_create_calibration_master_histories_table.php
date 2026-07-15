<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_master_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_master_id')
                ->constrained('calibration_masters')
                ->cascadeOnDelete();
            $table->date('calibration_date');
            $table->string('cert_no', 120)->nullable();
            $table->string('performed_by', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('cert_file_path', 500)->nullable();
            $table->string('cert_file_name', 255)->nullable();
            $table->string('cert_mime_type', 100)->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_master_histories');
    }
};
