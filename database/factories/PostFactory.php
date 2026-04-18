<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'post_type_id' => PostType::factory(),
            'body' => fake()->paragraph(),
        ];
    }

    public function text(): static
    {
        return $this->state(fn () => [
            'post_type_id' => PostType::firstOrCreate(
                ['slug' => 'text'],
                ['name' => 'Text', 'is_active' => true],
            )->id,
            'body' => fake()->paragraph(),
        ]);
    }

    public function image(int $count = 1): static
    {
        return $this->state(fn () => [
            'post_type_id' => PostType::firstOrCreate(
                ['slug' => 'image'],
                ['name' => 'Image', 'is_active' => true],
            )->id,
            'body' => fake()->optional()->sentence(),
        ])->afterCreating(function (Post $post) use ($count) {
            for ($i = 0; $i < $count; $i++) {
                PostMedia::factory()->create([
                    'post_id' => $post->id,
                    'sort_order' => $i,
                ]);
            }
        });
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'post_type_id' => PostType::firstOrCreate(
                ['slug' => 'video'],
                ['name' => 'Video', 'is_active' => true],
            )->id,
            'body' => fake()->optional()->sentence(),
        ])->afterCreating(function (Post $post) {
            PostMedia::factory()->create([
                'post_id' => $post->id,
                'sort_order' => 0,
            ]);
        });
    }
}
