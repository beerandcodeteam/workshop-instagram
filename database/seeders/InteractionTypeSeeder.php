<?php

namespace Database\Seeders;

use App\Models\InteractionType;
use Illuminate\Database\Seeder;

class InteractionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Curtida', 'slug' => 'like', 'default_weight' => 1.0, 'half_life_hours' => 720, 'is_positive' => true],
            ['name' => 'Descurtida', 'slug' => 'unlike', 'default_weight' => -0.5, 'half_life_hours' => 1, 'is_positive' => false],
            ['name' => 'Comentário', 'slug' => 'comment', 'default_weight' => 1.5, 'half_life_hours' => 720, 'is_positive' => true],
            ['name' => 'Compartilhamento', 'slug' => 'share', 'default_weight' => 2.0, 'half_life_hours' => 720, 'is_positive' => true],
            ['name' => 'Visualização', 'slug' => 'view', 'default_weight' => 0.5, 'half_life_hours' => 6, 'is_positive' => true],
            ['name' => 'Pulou rápido', 'slug' => 'skip_fast', 'default_weight' => -0.3, 'half_life_hours' => 6, 'is_positive' => false],
            ['name' => 'Esconder', 'slug' => 'hide', 'default_weight' => -1.5, 'half_life_hours' => 720, 'is_positive' => false],
            ['name' => 'Denunciar', 'slug' => 'report', 'default_weight' => -3.0, 'half_life_hours' => 2160, 'is_positive' => false],
            ['name' => 'Bloquear autor', 'slug' => 'author_block', 'default_weight' => 0.0, 'half_life_hours' => 87600, 'is_positive' => false],
        ];

        foreach ($types as $type) {
            InteractionType::firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'default_weight' => $type['default_weight'],
                    'half_life_hours' => $type['half_life_hours'],
                    'is_positive' => $type['is_positive'],
                    'is_active' => true,
                ],
            );
        }
    }
}
