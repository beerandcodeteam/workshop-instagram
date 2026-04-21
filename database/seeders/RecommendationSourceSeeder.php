<?php

namespace Database\Seeders;

use App\Models\RecommendationSource;
use Illuminate\Database\Seeder;

class RecommendationSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            ['name' => 'ANN long-term', 'slug' => 'ann_long_term'],
            ['name' => 'ANN short-term', 'slug' => 'ann_short_term'],
            ['name' => 'ANN cluster', 'slug' => 'ann_cluster'],
            ['name' => 'Trending', 'slug' => 'trending'],
            ['name' => 'Following', 'slug' => 'following'],
            ['name' => 'Locality', 'slug' => 'locality'],
            ['name' => 'Explore', 'slug' => 'explore'],
            ['name' => 'Control', 'slug' => 'control'],
        ];

        foreach ($sources as $source) {
            RecommendationSource::firstOrCreate(
                ['slug' => $source['slug']],
                ['name' => $source['name'], 'is_active' => true],
            );
        }
    }
}
