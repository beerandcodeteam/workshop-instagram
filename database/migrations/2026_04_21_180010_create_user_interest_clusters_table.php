<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('user_interest_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('cluster_index');
            $table->vector('embedding', 1536);
            $table->decimal('weight', 6, 4);
            $table->integer('sample_count');
            $table->foreignId('embedding_model_id')->constrained('embedding_models')->restrictOnDelete();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index('user_id', 'user_interest_clusters_user_idx');
            $table->unique(['user_id', 'cluster_index'], 'user_interest_clusters_user_cluster_unique');
        });

        DB::statement('CREATE INDEX user_interest_clusters_embedding_hnsw_idx ON user_interest_clusters USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS user_interest_clusters_embedding_hnsw_idx');
        Schema::dropIfExists('user_interest_clusters');
    }
};
