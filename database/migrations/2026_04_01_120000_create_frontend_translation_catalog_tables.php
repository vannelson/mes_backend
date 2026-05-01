<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // The frontend translation catalog scaffold was committed without
        // the intended table definitions. Keep this migration valid and
        // non-destructive so historical migration order remains intact.
    }

    public function down(): void
    {
        // No-op because no schema changes were applied in up().
    }
};
