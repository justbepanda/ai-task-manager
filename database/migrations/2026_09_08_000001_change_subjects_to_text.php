<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prefer raw SQL to avoid doctrine/dbal. SQLite has no MODIFY and does not
        // enforce VARCHAR length, so a no-op is enough for in-memory tests.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE messages MODIFY subject TEXT NULL');
        DB::statement('ALTER TABLE conversations MODIFY subject TEXT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE messages MODIFY subject VARCHAR(255) NULL');
        DB::statement('ALTER TABLE conversations MODIFY subject VARCHAR(255) NULL');
    }
};
