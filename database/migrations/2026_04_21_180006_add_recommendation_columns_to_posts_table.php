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

        Schema::table('posts', function (Blueprint $table) {
            $table->vector('embedding', 1536)->nullable();
            $table->timestamp('embedding_updated_at')->nullable();
            $table->foreignId('embedding_model_id')->nullable()->constrained('embedding_models')->nullOnDelete();

            $table->integer('reports_count')->default(0);
            $table->softDeletes();

            $table->index('reports_count', 'posts_reports_count_idx');
        });

        DB::statement('CREATE INDEX posts_embedding_hnsw_idx ON posts USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS posts_embedding_hnsw_idx');

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_reports_count_idx');
            $table->dropForeign(['embedding_model_id']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'embedding',
                'embedding_updated_at',
                'embedding_model_id',
                'reports_count',
            ]);
        });
    }
};
