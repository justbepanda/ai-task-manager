<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignId('manager_executor_id')
                ->nullable()
                ->after('status')
                ->constrained('executors')
                ->nullOnDelete();
        });

        Schema::create('executor_project_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('executor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_task_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['executor_id', 'project_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executor_project_task');

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_executor_id');
        });
    }
};
