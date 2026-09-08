<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_raws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('raw_headers')->nullable();
            $table->longText('raw_body')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_raws');
    }
};
