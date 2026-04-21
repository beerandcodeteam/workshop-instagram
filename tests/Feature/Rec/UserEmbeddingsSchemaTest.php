<?php

use Database\Seeders\EmbeddingModelSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(EmbeddingModelSeeder::class);
});

test('users_table_has_three_embedding_columns_and_metadata_pairs', function () {
    $columns = DB::table('information_schema.columns')
        ->where('table_name', 'users')
        ->pluck('column_name')
        ->all();

    expect($columns)
        ->toContain('long_term_embedding')
        ->toContain('long_term_embedding_updated_at')
        ->toContain('long_term_embedding_model_id')
        ->toContain('short_term_embedding')
        ->toContain('short_term_embedding_updated_at')
        ->toContain('short_term_embedding_model_id')
        ->toContain('avoid_embedding')
        ->toContain('avoid_embedding_updated_at')
        ->toContain('avoid_embedding_model_id');

    expect($columns)->not->toContain('embedding');

    foreach (['long_term_embedding', 'short_term_embedding', 'avoid_embedding'] as $vectorColumn) {
        $type = DB::selectOne(<<<SQL
            SELECT format_type(atttypid, atttypmod) AS type
            FROM pg_attribute
            WHERE attrelid = 'users'::regclass
              AND attname = '{$vectorColumn}'
        SQL);

        expect($type->type)->toBe('vector(1536)');
    }
});

test('hnsw_indexes_exist_on_all_three_vectors', function () {
    $expected = [
        'users_lt_embedding_hnsw_idx' => 'long_term_embedding',
        'users_st_embedding_hnsw_idx' => 'short_term_embedding',
        'users_avoid_embedding_hnsw_idx' => 'avoid_embedding',
    ];

    foreach ($expected as $indexName => $column) {
        $index = DB::table('pg_indexes')
            ->where('tablename', 'users')
            ->where('indexname', $indexName)
            ->first();

        expect($index)->not->toBeNull("Index {$indexName} should exist");
        expect($index->indexdef)->toContain('hnsw');
        expect($index->indexdef)->toContain('vector_cosine_ops');
        expect($index->indexdef)->toContain("WHERE ({$column} IS NOT NULL)");
    }
});
