<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('virtual_screens', function (Blueprint $table) {
            $table->string('access_code', 6)->nullable()->after('share_token');
            $table->unique('access_code');
        });

        $existingCodes = DB::table('virtual_screens')
            ->pluck('access_code')
            ->filter()
            ->all();

        $existingMap = array_flip($existingCodes);

        DB::table('virtual_screens')
            ->whereNull('access_code')
            ->select('id')
            ->orderBy('id')
            ->chunk(100, function ($rows) use (&$existingMap) {
                foreach ($rows as $row) {
                    $code = $this->generateUniqueCode($existingMap);
                    $existingMap[$code] = true;
                    DB::table('virtual_screens')->where('id', $row->id)->update([
                        'access_code' => $code,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_screens', function (Blueprint $table) {
            $table->dropUnique(['access_code']);
            $table->dropColumn('access_code');
        });
    }

    protected function generateUniqueCode(array $used): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
        } while (isset($used[$code]));

        return $code;
    }
};
