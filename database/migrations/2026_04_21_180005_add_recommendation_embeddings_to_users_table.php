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

        Schema::table('users', function (Blueprint $table) {
            $table->vector('long_term_embedding', 1536)->nullable();
            $table->timestamp('long_term_embedding_updated_at')->nullable();
            $table->foreignId('long_term_embedding_model_id')->nullable()->constrained('embedding_models')->nullOnDelete();

            $table->vector('short_term_embedding', 1536)->nullable();
            $table->timestamp('short_term_embedding_updated_at')->nullable();
            $table->foreignId('short_term_embedding_model_id')->nullable()->constrained('embedding_models')->nullOnDelete();

            $table->vector('avoid_embedding', 1536)->nullable();
            $table->timestamp('avoid_embedding_updated_at')->nullable();
            $table->foreignId('avoid_embedding_model_id')->nullable()->constrained('embedding_models')->nullOnDelete();
        });

        DB::statement('CREATE INDEX users_lt_embedding_hnsw_idx ON users USING hnsw (long_term_embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX users_st_embedding_hnsw_idx ON users USING hnsw (short_term_embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX users_avoid_embedding_hnsw_idx ON users USING hnsw (avoid_embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_lt_embedding_hnsw_idx');
        DB::statement('DROP INDEX IF EXISTS users_st_embedding_hnsw_idx');
        DB::statement('DROP INDEX IF EXISTS users_avoid_embedding_hnsw_idx');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['long_term_embedding_model_id']);
            $table->dropForeign(['short_term_embedding_model_id']);
            $table->dropForeign(['avoid_embedding_model_id']);

            $table->dropColumn([
                'long_term_embedding',
                'long_term_embedding_updated_at',
                'long_term_embedding_model_id',
                'short_term_embedding',
                'short_term_embedding_updated_at',
                'short_term_embedding_model_id',
                'avoid_embedding',
                'avoid_embedding_updated_at',
                'avoid_embedding_model_id',
            ]);
        });
    }
};
