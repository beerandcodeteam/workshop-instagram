<?php

use App\Models\Post;
use Database\Seeders\EmbeddingModelSeeder;
use Database\Seeders\PostTypeSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(PostTypeSeeder::class);
    $this->seed(EmbeddingModelSeeder::class);
});

test('posts_table_has_embedding_columns', function () {
    $columns = DB::table('information_schema.columns')
        ->where('table_name', 'posts')
        ->whereIn('column_name', [
            'embedding',
            'embedding_updated_at',
            'embedding_model_id',
            'reports_count',
        ])
        ->pluck('column_name')
        ->all();

    expect($columns)
        ->toContain('embedding')
        ->toContain('embedding_updated_at')
        ->toContain('embedding_model_id')
        ->toContain('reports_count');

    $embeddingType = DB::selectOne(<<<'SQL'
        SELECT format_type(atttypid, atttypmod) AS type
        FROM pg_attribute
        WHERE attrelid = 'posts'::regclass
          AND attname = 'embedding'
    SQL);

    expect($embeddingType->type)->toBe('vector(1536)');

    $reportsCountDefault = DB::table('information_schema.columns')
        ->where('table_name', 'posts')
        ->where('column_name', 'reports_count')
        ->value('column_default');

    expect((int) $reportsCountDefault)->toBe(0);
});

test('hnsw_index_exists_on_posts_embedding', function () {
    $index = DB::table('pg_indexes')
        ->where('tablename', 'posts')
        ->where('indexname', 'posts_embedding_hnsw_idx')
        ->first();

    expect($index)->not->toBeNull();
    expect($index->indexdef)->toContain('hnsw');
    expect($index->indexdef)->toContain('vector_cosine_ops');
    expect($index->indexdef)->toContain('WHERE (embedding IS NOT NULL)');
});

test('backfill_migrated_existing_post_embeddings', function () {
    if (! DB::getSchemaBuilder()->hasTable('post_embeddings')) {
        $this->markTestSkipped('post_embeddings table no longer exists; backfill already completed.');
    }

    $post = Post::factory()->text()->createQuietly();
    DB::table('posts')->where('id', $post->id)->update([
        'embedding' => null,
        'embedding_updated_at' => null,
        'embedding_model_id' => null,
    ]);

    $vector = '['.implode(',', array_fill(0, 1536, 0.25)).']';

    DB::table('post_embeddings')->insert([
        'post_id' => $post->id,
        'embedding' => $vector,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $backfill = include database_path('migrations/2026_04_22_000002_backfill_posts_embedding_from_post_embeddings.php');
    $backfill->up();

    $fresh = Post::find($post->id);

    expect($fresh->embedding)->not->toBeNull();
    expect($fresh->embedding_updated_at)->not->toBeNull();
    expect($fresh->embedding_model_id)->not->toBeNull();
});
