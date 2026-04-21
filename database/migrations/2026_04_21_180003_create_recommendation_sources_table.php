<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('slug', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_sources');
    }
};
