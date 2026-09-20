<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->boolean('auto_assign_projects_llm')->default(true)->after('auto_create_confidence_threshold');
            $table->decimal('auto_assign_llm_confidence_threshold', 3, 2)->default(0.90)->after('auto_assign_projects_llm');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'auto_assign_projects_llm',
                'auto_assign_llm_confidence_threshold',
            ]);
        });
    }
};
