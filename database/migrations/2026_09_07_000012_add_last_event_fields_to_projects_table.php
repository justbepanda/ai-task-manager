<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('last_event_type')->nullable()->after('status');
            $table->timestamp('last_event_at')->nullable()->after('last_event_type');
            $table->text('last_summary')->nullable()->after('last_event_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['last_event_type', 'last_event_at', 'last_summary']);
        });
    }
};
