<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('guest_profiles', function (Blueprint $table) {
            $table->string('id_image_path')->nullable()->after('id_number');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('guest_id_image_path')->nullable()->after('guest_id_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_profiles', function (Blueprint $table) {
            $table->dropColumn('id_image_path');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('guest_id_image_path');
        });
    }
};
