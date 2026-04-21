<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 100);
            $table->text('details')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('post_id', 'reports_post_id_idx');
            $table->unique(['user_id', 'post_id'], 'reports_user_post_unique');
            $table->index('resolved_at', 'reports_resolved_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
