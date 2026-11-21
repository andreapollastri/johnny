<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access keys that reach every bucket. Garage only knows permissions per bucket, so the panel
     * remembers which keys are global and grants them on each bucket, new ones included.
     */
    public function up(): void
    {
        Schema::create('global_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 64)->unique();
            $table->string('name', 128);
            $table->string('scope', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_keys');
    }
};
