<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transfer that repeats: every `interval_minutes`, a bucket here is brought in line with a
     * bucket at another provider, in one direction. Each run is a row of `transfers`.
     */
    public function up(): void
    {
        Schema::create('syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remote_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 8);
            $table->string('mode', 8)->default('copy');
            $table->string('local_bucket');
            $table->string('remote_bucket');
            $table->string('prefix', 1024)->default('');
            $table->unsignedInteger('interval_minutes');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syncs');
    }
};
