<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Alter the ENUM type to include 'video' and 'audio'
        DB::statement("ALTER TABLE playlist_items MODIFY COLUMN type ENUM('url', 'widget', 'image', 'pdf', 'video', 'audio') NOT NULL DEFAULT 'url'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'video' and 'audio' from the ENUM type
        DB::statement("ALTER TABLE playlist_items MODIFY COLUMN type ENUM('url', 'widget', 'image', 'pdf') NOT NULL DEFAULT 'url'");
    }
};
