<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Drop post_embeddings table (Phase 2.1.4 — FINAL)
|--------------------------------------------------------------------------
|
| Esta migration remove a tabela `post_embeddings`, cujo conteúdo foi
| consolidado em `posts.embedding` pelas migrations anteriores desta fase
| (§2.1.2 HNSW com WHERE e §2.1.3 backfill).
|
| PRÉ-REQUISITO: rodar somente DEPOIS que as Phases 2.2 (job async gravando
| em `posts.embedding`) e 2.3 (re-embed on update) estiverem estáveis e
| todos os consumidores que ainda lêem `post_embeddings` tiverem sido
| migrados para `posts.embedding` (ex.: `CalculateUserCentroidJob`,
| `Livewire\Pages\Feed\Index`).
|
| Para evitar dropar a tabela cedo demais em ambientes onde os consumidores
| acima ainda não foram refatorados, o DROP é condicionado à flag de
| ambiente `DROP_POST_EMBEDDINGS_TABLE=true`. Deixe a flag desligada até
| validar que Phases 3 e 5 (refactor dos consumidores) estão em produção.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! env('DROP_POST_EMBEDDINGS_TABLE', false)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS embedding_hnsw_idx');

        Schema::dropIfExists('post_embeddings');
    }

    public function down(): void
    {
        //
    }
};
