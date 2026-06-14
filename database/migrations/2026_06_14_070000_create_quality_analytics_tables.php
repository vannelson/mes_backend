<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_analytics_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80)->default('dashboard')->index();
            $table->string('engine_name', 120)->default('matplotlib-spc')->index();
            $table->string('engine_version', 40)->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable()->index();
            $table->dateTime('completed_at')->nullable()->index();
            $table->json('filters')->nullable();
            $table->json('summary_metrics')->nullable();
            $table->json('capability_results')->nullable();
            $table->json('rule_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_analytics_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_analytics_run_id')
                ->constrained('quality_analytics_runs', indexName: 'qa_charts_run_fk')
                ->cascadeOnDelete();
            $table->string('module_key', 80)->index();
            $table->string('chart_key', 120)->index();
            $table->string('chart_type', 80)->index();
            $table->string('title', 255);
            $table->string('image_path', 255)->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->boolean('is_spc')->default(false)->index();
            $table->json('filters')->nullable();
            $table->json('series_payload')->nullable();
            $table->json('stat_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_analytics_rule_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_analytics_run_id')
                ->constrained('quality_analytics_runs', indexName: 'qa_rule_run_fk')
                ->cascadeOnDelete();
            $table->foreignId('quality_analytics_chart_id')
                ->nullable()
                ->constrained('quality_analytics_charts', indexName: 'qa_rule_chart_fk')
                ->nullOnDelete();
            $table->string('module_key', 80)->index();
            $table->string('rule_code', 80)->index();
            $table->string('severity', 40)->default('info')->index();
            $table->string('message', 255);
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_analytics_source_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quality_analytics_run_id')
                ->constrained('quality_analytics_runs', indexName: 'qa_src_run_fk')
                ->cascadeOnDelete();
            $table->foreignId('quality_analytics_chart_id')
                ->nullable()
                ->constrained('quality_analytics_charts', indexName: 'qa_src_chart_fk')
                ->nullOnDelete();
            $table->string('source_module', 80)->index();
            $table->string('source_type', 160)->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_analytics_source_links');
        Schema::dropIfExists('quality_analytics_rule_violations');
        Schema::dropIfExists('quality_analytics_charts');
        Schema::dropIfExists('quality_analytics_runs');
    }
};
