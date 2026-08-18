<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert legacy cashier login accounts to Front Desk and tighten the
     * users.role constraint. Historical financial records are not touched.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'cashier')->update(['role' => 'front_desk']);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'front_desk', 'housekeeping') NOT NULL DEFAULT 'front_desk'");
        }
    }

    /**
     * Restore the previous enum so cashier can exist again. Converted users
     * stay Front Desk — original cashier identities cannot be reconstructed.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'front_desk', 'cashier', 'housekeeping') NOT NULL DEFAULT 'front_desk'");
        }
    }
};
