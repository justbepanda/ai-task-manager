<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_filter_settings')) {
            return;
        }

        Schema::create('mail_filter_settings', function (Blueprint $table) {
            $table->id();
            $table->json('blocked_emails')->nullable();
            $table->json('blocked_domains')->nullable();
            $table->json('subject_stop_words')->nullable();
            $table->json('body_stop_words')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_filter_settings');
    }
};
