<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table) {
            // Historical/closed rows remain NULL. A unique non-NULL key makes
            // simultaneous attempts to open the one physical register safe.
            $table->string('active_register_key', 64)
                ->nullable()
                ->after('user_id')
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('shift_sessions', function (Blueprint $table) {
            $table->dropUnique(['active_register_key']);
            $table->dropColumn('active_register_key');
        });
    }
};
