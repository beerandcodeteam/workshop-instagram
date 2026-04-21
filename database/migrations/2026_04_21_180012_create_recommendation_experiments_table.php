<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('experiment_name', 100);
            $table->string('variant', 50);
            $table->timestamp('assigned_at');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'experiment_name'], 'recommendation_experiments_user_experiment_unique');
            $table->index(['experiment_name', 'variant'], 'recommendation_experiments_exp_variant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_experiments');
    }
};
