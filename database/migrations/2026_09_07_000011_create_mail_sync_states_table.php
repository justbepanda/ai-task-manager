<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('folder');
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_states');
    }
};
