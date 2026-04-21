<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 3.1.3 — HNSW parcial nos 3 vetores de users
|--------------------------------------------------------------------------
|
| Os índices HNSW criados no Phase 0 cobrem a coluna inteira; agora
| aplicamos o filtro `WHERE <coluna> IS NOT NULL` para evitar overhead
| em linhas sem vetor (a maior parte da base hoje).
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_lt_embedding_hnsw_idx');
        DB::statement('DROP INDEX IF EXISTS users_st_embedding_hnsw_idx');
        DB::statement('DROP INDEX IF EXISTS users_avoid_embedding_hnsw_idx');

        DB::statement('CREATE INDEX users_lt_embedding_hnsw_idx ON users USING hnsw (long_term_embedding vector_cosine_ops) WHERE long_term_embedding IS NOT NULL');
        DB::statement('CREATE INDEX users_st_embedding_hnsw_idx ON users USING hnsw (short_term_embedding vector_cosine_ops) WHERE short_term_embedding IS NOT NULL');
        DB::statement('CREATE INDEX users_avoid_embedding_hnsw_idx ON users USING hnsw (avoid_embedding vector_cosine_ops) WHERE avoid_embedding IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_lt_embedding_hnsw_idx');
        DB::statement('DROP INDEX IF EXISTS users_st_embedding_hnsw_idx');
        DB::statement('DROP INDEX IF EXISTS users_avoid_embedding_hnsw_idx');

        DB::statement('CREATE INDEX users_lt_embedding_hnsw_idx ON users USING hnsw (long_term_embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX users_st_embedding_hnsw_idx ON users USING hnsw (short_term_embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX users_avoid_embedding_hnsw_idx ON users USING hnsw (avoid_embedding vector_cosine_ops)');
    }
};
