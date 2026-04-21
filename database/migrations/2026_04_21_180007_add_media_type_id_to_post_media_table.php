<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->foreignId('media_type_id')->nullable()->constrained('media_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropForeign(['media_type_id']);
            $table->dropColumn('media_type_id');
        });
    }
};
