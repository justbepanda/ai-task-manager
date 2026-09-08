<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender');
            $table->json('recipients');
            $table->json('cc')->nullable();
            $table->text('subject')->nullable();
            $table->longText('body')->nullable();
            $table->timestamp('received_at');
            $table->json('headers')->nullable();
            $table->json('metadata')->nullable();
            $table->string('classification')->nullable();
            $table->string('processing_status')->default('pending');
            $table->text('ai_summary')->nullable();
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->boolean('requires_manager')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('classification');
            $table->index('processing_status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
