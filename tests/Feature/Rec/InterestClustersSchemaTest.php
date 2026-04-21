<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('table_and_hnsw_index_exist', function () {
    expect(Schema::hasTable('user_interest_clusters'))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->where('table_name', 'user_interest_clusters')
        ->pluck('column_name')
        ->all();

    expect($columns)
        ->toContain('user_id')
        ->toContain('cluster_index')
        ->toContain('embedding')
        ->toContain('weight')
        ->toContain('sample_count')
        ->toContain('embedding_model_id')
        ->toContain('computed_at');

    $embeddingType = DB::selectOne(<<<'SQL'
        SELECT format_type(atttypid, atttypmod) AS type
        FROM pg_attribute
        WHERE attrelid = 'user_interest_clusters'::regclass
          AND attname = 'embedding'
    SQL);

    expect($embeddingType->type)->toBe('vector(1536)');

    $hnsw = DB::table('pg_indexes')
        ->where('tablename', 'user_interest_clusters')
        ->where('indexname', 'user_interest_clusters_embedding_hnsw_idx')
        ->first();

    expect($hnsw)->not->toBeNull();
    expect($hnsw->indexdef)->toContain('hnsw');
    expect($hnsw->indexdef)->toContain('vector_cosine_ops');

    $unique = DB::table('pg_indexes')
        ->where('tablename', 'user_interest_clusters')
        ->where('indexname', 'user_interest_clusters_user_cluster_unique')
        ->first();

    expect($unique)->not->toBeNull();
});
