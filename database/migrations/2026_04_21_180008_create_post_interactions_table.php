<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interaction_type_id')->constrained('interaction_types')->restrictOnDelete();
            $table->decimal('weight', 6, 3);
            $table->string('session_id', 64)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at'], 'post_interactions_user_created_idx');
            $table->index(['post_id', 'created_at'], 'post_interactions_post_created_idx');
            $table->index(['interaction_type_id', 'created_at'], 'post_interactions_type_created_idx');
            $table->index(['user_id', 'post_id', 'interaction_type_id'], 'post_interactions_user_post_type_idx');
            $table->index('session_id', 'post_interactions_session_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_interactions');
    }
};
