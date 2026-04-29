<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->string('customer_part_no', 120)->nullable()->after('customer_part_number_ref');
            $table->unsignedInteger('template_route_version')->default(1)->after('customer_part_no');
            $table->boolean('is_active')->default(true)->after('template_route_version');
            $table->unsignedBigInteger('parent_template_route_id')->nullable()->after('is_active');
            $table->unsignedBigInteger('created_from_template_route_id')->nullable()->after('parent_template_route_id');

            $table->index('customer_part_no');
            $table->index(['customer_part_no', 'is_active']);
            $table->unique(['customer_part_no', 'template_route_version'], 'template_routes_customer_part_version_unique');
        });

        DB::table('template_routes')
            ->select(['id', 'customer_part_number_ref'])
            ->orderBy('id')
            ->chunkById(200, function ($routes): void {
                foreach ($routes as $route) {
                    $raw = trim((string) ($route->customer_part_number_ref ?? ''));
                    $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
                    $parts = array_values(array_unique(array_filter(array_map(
                        static fn ($part) => strtoupper(trim((string) $part)),
                        $parts
                    ))));

                    DB::table('template_routes')
                        ->where('id', $route->id)
                        ->update([
                            'customer_part_no' => count($parts) === 1 ? $parts[0] : null,
                            'template_route_version' => 1,
                            'is_active' => true,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('template_routes', function (Blueprint $table) {
            $table->dropUnique('template_routes_customer_part_version_unique');
            $table->dropIndex(['customer_part_no']);
            $table->dropIndex(['customer_part_no', 'is_active']);
            $table->dropColumn([
                'customer_part_no',
                'template_route_version',
                'is_active',
                'parent_template_route_id',
                'created_from_template_route_id',
            ]);
        });
    }
};
