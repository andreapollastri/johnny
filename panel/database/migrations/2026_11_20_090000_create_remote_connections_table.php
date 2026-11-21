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
        Schema::create('remote_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('provider', 32);
            $table->string('endpoint')->nullable();
            $table->string('region', 64)->nullable();
            // Both halves of the credentials are encrypted with APP_KEY (see the model casts).
            $table->text('access_key_id');
            $table->text('secret_access_key');
            $table->boolean('path_style')->default(true);
            // SFTP only: the server's host keys, pinned the first time it answers.
            $table->text('host_key')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('check_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_connections');
    }
};
