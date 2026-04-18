<?php

namespace Database\Seeders;

use App\Models\PostType;
use Illuminate\Database\Seeder;

class PostTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Texto', 'slug' => 'text'],
            ['name' => 'Imagem', 'slug' => 'image'],
            ['name' => 'Video', 'slug' => 'video'],
        ];

        foreach ($types as $type) {
            PostType::updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name'], 'is_active' => true],
            );
        }
    }
}
