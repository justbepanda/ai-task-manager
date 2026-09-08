<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_company_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unique(['client_company_id', 'project_id']);
        });

        Schema::create('client_contact_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_contact_id')->constrained('client_contacts')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unique(['client_contact_id', 'project_id']);
        });

        Schema::create('executor_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('executor_id')->constrained('executors')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unique(['executor_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executor_project');
        Schema::dropIfExists('client_contact_project');
        Schema::dropIfExists('client_company_project');
    }
};
