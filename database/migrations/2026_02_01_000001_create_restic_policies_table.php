<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restic_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('server_uuid')->unique();
            $table->unsignedInteger('interval_value')->nullable();
            $table->string('interval_unit', 16)->nullable();
            $table->boolean('schedule_enabled')->default(false);
            $table->timestamp('schedule_last_run_at')->nullable();

            $table->unsignedInteger('keep_last')->nullable();
            $table->unsignedInteger('keep_daily')->nullable();
            $table->unsignedInteger('keep_weekly')->nullable();
            $table->unsignedInteger('keep_monthly')->nullable();
            $table->unsignedInteger('keep_yearly')->nullable();
            $table->string('keep_within', 64)->nullable();
            $table->boolean('pruning_enabled')->default(false);
            $table->timestamp('pruning_last_run_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restic_policies');
    }
};
