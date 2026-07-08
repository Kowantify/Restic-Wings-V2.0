<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('restic_backup_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('server_uuid');
            $table->string('backup_id', 64);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['server_uuid', 'backup_id']);
            $table->index(['server_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restic_backup_notes');
    }
};
