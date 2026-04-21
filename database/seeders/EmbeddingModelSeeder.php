<?php

namespace Database\Seeders;

use App\Models\EmbeddingModel;
use Illuminate\Database\Seeder;

class EmbeddingModelSeeder extends Seeder
{
    public function run(): void
    {
        EmbeddingModel::firstOrCreate(
            ['slug' => 'gemini-embedding-2-preview'],
            [
                'name' => 'Gemini Embedding 2 Preview',
                'provider' => 'google',
                'dimensions' => 1536,
                'is_active' => true,
            ],
        );
    }
}
