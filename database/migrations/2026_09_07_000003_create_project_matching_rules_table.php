<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_matching_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('client_emails')->nullable();
            $table->json('staff_emails')->nullable();
            $table->json('domains')->nullable();
            $table->json('subject_patterns')->nullable();
            $table->json('keywords')->nullable();
            $table->json('technical_sources')->nullable();
            $table->json('custom_rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_matching_rules');
    }
};
