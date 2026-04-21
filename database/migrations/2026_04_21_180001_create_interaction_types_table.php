<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interaction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('slug', 50)->unique();
            $table->decimal('default_weight', 6, 3);
            $table->integer('half_life_hours');
            $table->boolean('is_positive');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interaction_types');
    }
};
