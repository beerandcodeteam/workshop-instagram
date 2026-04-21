<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Phase 3.1.1 — "Rename" users.embedding → users.long_term_embedding
|--------------------------------------------------------------------------
|
| O Phase 0 já criou users.long_term_embedding em paralelo à coluna legada
| users.embedding. Aqui finalizamos a transição: copiamos qualquer vetor
| ainda preso em users.embedding para users.long_term_embedding (quando
| este último estiver vazio) e dropamos a coluna legada.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'embedding')) {
            return;
        }

        $modelId = DB::table('embedding_models')
            ->where('slug', 'gemini-embedding-2-preview')
            ->value('id');

        DB::statement(<<<'SQL'
            UPDATE users
            SET
                long_term_embedding = embedding,
                long_term_embedding_updated_at = COALESCE(long_term_embedding_updated_at, NOW()),
                long_term_embedding_model_id = COALESCE(long_term_embedding_model_id, ?)
            WHERE embedding IS NOT NULL
              AND long_term_embedding IS NULL
        SQL, [$modelId]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }

    public function down(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::table('users', function (Blueprint $table) {
            $table->vector('embedding', 1536)->nullable();
        });
    }
};
