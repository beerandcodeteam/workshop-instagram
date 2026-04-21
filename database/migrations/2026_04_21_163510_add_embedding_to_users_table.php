<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_embeddings');

        Schema::ensureVectorExtensionExists();

        Schema::table('users', function (Blueprint $table) {
            $table->vector('embedding', 1536)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }
};
