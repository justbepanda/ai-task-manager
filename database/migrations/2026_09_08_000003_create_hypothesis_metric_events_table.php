<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hypothesis_metric_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_action_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hypothesis_metric_events');
    }
};
