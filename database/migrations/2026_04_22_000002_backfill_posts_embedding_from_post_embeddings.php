<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('post_embeddings')) {
            return;
        }

        $modelId = DB::table('embedding_models')
            ->where('slug', 'gemini-embedding-2-preview')
            ->value('id');

        DB::statement(<<<'SQL'
            UPDATE posts
            SET
                embedding = pe.embedding,
                embedding_updated_at = pe.created_at,
                embedding_model_id = ?
            FROM post_embeddings pe
            WHERE pe.post_id = posts.id
              AND posts.embedding IS NULL
        SQL, [$modelId]);
    }

    public function down(): void
    {
        //
    }
};
