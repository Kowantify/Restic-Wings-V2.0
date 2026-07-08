<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('restic', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36)->unique();
            $table->string('encryption_key', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restic');
    }
};
