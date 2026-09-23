<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->string('external_key', 191);
            $table->string('title')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_key']);
            $table->index(['project_id', 'status']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('project_task_id')
                ->nullable()
                ->after('project_id')
                ->constrained('project_tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_task_id');
        });

        Schema::dropIfExists('project_tasks');
    }
};
