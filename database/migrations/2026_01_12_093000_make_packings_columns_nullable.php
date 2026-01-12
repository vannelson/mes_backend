<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE packings MODIFY wd_part_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY material VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY description TEXT NULL');
        DB::statement('ALTER TABLE packings MODIFY image VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY design VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY shipping_location VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY customer_code VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY box_size VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY qty_per_box VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY rolls_per_box VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY core_label_left VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY core_label_right VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY hm_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY ul_label_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY cas VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY important VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY code_1 VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY underline_code VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY colour_code VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY wd_revision VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY revised_by_pic VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY date_of_revised DATE NULL');
        DB::statement('ALTER TABLE packings MODIFY remarks TEXT NULL');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE packings MODIFY wd_part_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY material VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY description TEXT NULL');
        DB::statement('ALTER TABLE packings MODIFY image VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY design VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY shipping_location VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY customer_code VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY box_size VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY qty_per_box VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY rolls_per_box VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY core_label_left VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY core_label_right VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY hm_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY ul_label_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY cas VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY important VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY code_1 VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY underline_code VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY colour_code VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY wd_revision VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY revised_by_pic VARCHAR(255) NULL');
        DB::statement('ALTER TABLE packings MODIFY date_of_revised DATE NULL');
        DB::statement('ALTER TABLE packings MODIFY remarks TEXT NULL');
    }
};
