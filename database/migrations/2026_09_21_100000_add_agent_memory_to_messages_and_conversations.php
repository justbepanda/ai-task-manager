<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('agent_facts')->nullable()->after('ai_confidence');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->json('agent_state')->nullable()->after('external_thread_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('agent_facts');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('agent_state');
        });
    }
};
