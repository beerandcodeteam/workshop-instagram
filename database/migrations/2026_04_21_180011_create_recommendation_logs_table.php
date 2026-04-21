<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommendation_source_id')->nullable()->constrained('recommendation_sources')->nullOnDelete();
            $table->decimal('score', 10, 6);
            $table->integer('rank_position');
            $table->jsonb('scores_breakdown')->nullable();
            $table->string('filtered_reason', 100)->nullable();
            $table->string('experiment_variant', 50)->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'post_id', 'created_at'], 'recommendation_logs_user_post_created_idx');
            $table->index('request_id', 'recommendation_logs_request_idx');
            $table->index('created_at', 'recommendation_logs_created_idx');
            $table->index(['recommendation_source_id', 'created_at'], 'recommendation_logs_source_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_logs');
    }
};
