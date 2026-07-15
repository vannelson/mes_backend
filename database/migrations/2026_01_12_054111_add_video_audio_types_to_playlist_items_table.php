<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite' || !Schema::hasTable('playlist_items')) {
            return;
        }

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', ['url', 'widget', 'image', 'pdf', 'video', 'audio'])->default('url')->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite' || !Schema::hasTable('playlist_items')) {
            return;
        }

        Schema::table('playlist_items', function (Blueprint $table) {
            $table->enum('type', ['url', 'widget', 'image', 'pdf'])->default('url')->change();
        });
    }
};
