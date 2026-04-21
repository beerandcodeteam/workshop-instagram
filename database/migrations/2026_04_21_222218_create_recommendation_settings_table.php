<?php

// Phase 8.1 — US-020: armazenamento de overrides em runtime para `config/recommendation.php`.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->jsonb('value');
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_settings');
    }
};
