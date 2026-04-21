<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 120);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('message_group_participants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('message_group_id')->constrained('message_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['message_group_id', 'user_id']);
            $table->index(['user_id', 'message_group_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('recipient_id')
                ->constrained('message_groups')
                ->cascadeOnDelete();
            $table->index(['group_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::dropIfExists('message_group_participants');
        Schema::dropIfExists('message_groups');
    }
};
