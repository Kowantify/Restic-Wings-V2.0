<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restic_job_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('server_uuid')->index();
            $table->string('job_type', 50)->index();
            $table->string('status', 20)->index();
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restic_job_history');
    }
};
