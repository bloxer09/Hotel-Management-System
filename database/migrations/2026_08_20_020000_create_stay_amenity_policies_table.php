<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_amenity_policies', function (Blueprint $table) {
            $table->id();
            $table->string('stay_key', 32);
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->unsignedInteger('default_quantity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['stay_key', 'inventory_item_id'], 'stay_amenity_stay_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_amenity_policies');
    }
};
