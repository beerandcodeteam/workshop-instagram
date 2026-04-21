<?php

namespace Database\Factories;

use App\Models\InteractionType;
use App\Models\Post;
use App\Models\PostInteraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostInteraction>
 */
class PostInteractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->resolveTypeBySlug('like');

        return [
            'user_id' => User::factory(),
            'post_id' => Post::factory()->text(),
            'interaction_type_id' => $type->id,
            'weight' => $type->default_weight,
            'session_id' => fake()->uuid(),
            'duration_ms' => null,
            'context' => null,
            'created_at' => now(),
        ];
    }

    public function like(): static
    {
        return $this->state(function () {
            $type = $this->resolveTypeBySlug('like');

            return [
                'interaction_type_id' => $type->id,
                'weight' => $type->default_weight,
                'duration_ms' => null,
            ];
        });
    }

    public function comment(): static
    {
        return $this->state(function () {
            $type = $this->resolveTypeBySlug('comment');

            return [
                'interaction_type_id' => $type->id,
                'weight' => $type->default_weight,
                'duration_ms' => null,
            ];
        });
    }

    public function view(): static
    {
        return $this->state(function () {
            $type = $this->resolveTypeBySlug('view');

            return [
                'interaction_type_id' => $type->id,
                'weight' => $type->default_weight,
                'duration_ms' => fake()->numberBetween(500, 30_000),
            ];
        });
    }

    public function hide(): static
    {
        return $this->state(function () {
            $type = $this->resolveTypeBySlug('hide');

            return [
                'interaction_type_id' => $type->id,
                'weight' => $type->default_weight,
                'duration_ms' => null,
            ];
        });
    }

    public function report(): static
    {
        return $this->state(function () {
            $type = $this->resolveTypeBySlug('report');

            return [
                'interaction_type_id' => $type->id,
                'weight' => $type->default_weight,
                'duration_ms' => null,
            ];
        });
    }

    private function resolveTypeBySlug(string $slug): InteractionType
    {
        return InteractionType::firstOrCreate(
            ['slug' => $slug],
            match ($slug) {
                'like' => ['name' => 'Curtida', 'default_weight' => 1.0, 'half_life_hours' => 720, 'is_positive' => true, 'is_active' => true],
                'unlike' => ['name' => 'Descurtida', 'default_weight' => -0.5, 'half_life_hours' => 1, 'is_positive' => false, 'is_active' => true],
                'comment' => ['name' => 'Comentário', 'default_weight' => 1.5, 'half_life_hours' => 720, 'is_positive' => true, 'is_active' => true],
                'share' => ['name' => 'Compartilhamento', 'default_weight' => 2.0, 'half_life_hours' => 720, 'is_positive' => true, 'is_active' => true],
                'view' => ['name' => 'Visualização', 'default_weight' => 0.5, 'half_life_hours' => 6, 'is_positive' => true, 'is_active' => true],
                'skip_fast' => ['name' => 'Pulou rápido', 'default_weight' => -0.3, 'half_life_hours' => 6, 'is_positive' => false, 'is_active' => true],
                'hide' => ['name' => 'Esconder', 'default_weight' => -1.5, 'half_life_hours' => 720, 'is_positive' => false, 'is_active' => true],
                'report' => ['name' => 'Denunciar', 'default_weight' => -3.0, 'half_life_hours' => 2160, 'is_positive' => false, 'is_active' => true],
                'author_block' => ['name' => 'Bloquear autor', 'default_weight' => 0.0, 'half_life_hours' => 87600, 'is_positive' => false, 'is_active' => true],
            },
        );
    }
}
