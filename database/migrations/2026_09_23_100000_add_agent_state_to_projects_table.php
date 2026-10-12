<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'agent_state')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->json('agent_state')->nullable()->after('last_summary');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'agent_state')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('agent_state');
        });
    }
};
