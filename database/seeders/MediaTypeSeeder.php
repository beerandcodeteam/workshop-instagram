<?php

namespace Database\Seeders;

use App\Models\MediaType;
use Illuminate\Database\Seeder;

class MediaTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Imagem', 'slug' => 'image', 'mime_prefix' => 'image/'],
            ['name' => 'Vídeo', 'slug' => 'video', 'mime_prefix' => 'video/'],
            ['name' => 'Áudio', 'slug' => 'audio', 'mime_prefix' => 'audio/'],
        ];

        foreach ($types as $type) {
            MediaType::firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'mime_prefix' => $type['mime_prefix'],
                    'is_active' => true,
                ],
            );
        }
    }
}
