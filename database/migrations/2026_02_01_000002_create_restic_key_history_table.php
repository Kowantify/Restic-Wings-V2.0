<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('restic_key_history', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36);
            $table->string('owner_username', 191)->nullable();
            $table->string('encryption_key', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['server_uuid', 'owner_username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restic_key_history');
    }
};