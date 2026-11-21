<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remote_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Set on the runs of a scheduled sync; they go away with it.
            $table->foreignId('sync_id')->nullable()->constrained()->cascadeOnDelete();
            // Kept on the row so the history still reads after its connection is deleted.
            $table->string('connection_name');
            $table->string('provider', 32);
            $table->string('direction', 8);
            $table->string('mode', 8)->default('copy');
            $table->string('local_bucket');
            $table->string('remote_bucket');
            $table->string('prefix', 1024)->default('');
            $table->boolean('dry_run')->default(false);
            $table->boolean('create_bucket')->default(false);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('bytes_done')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->unsignedBigInteger('files_done')->default(0);
            $table->unsignedBigInteger('files_total')->default(0);
            $table->unsignedBigInteger('checks_done')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedBigInteger('speed')->default(0);
            $table->unsignedInteger('eta')->nullable();
            $table->text('message')->nullable();
            $table->longText('log')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
