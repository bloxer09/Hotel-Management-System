<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed default values
        DB::table('settings')->insert([
            ['key' => 'vat_enabled', 'value' => '0', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'vat_percent', 'value' => '12', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'or_prefix', 'value' => 'OR', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'or_sequence', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
