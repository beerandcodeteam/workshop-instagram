<?php

namespace Database\Factories;

use App\Models\PostType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostType>
 */
class PostTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => str()->slug($name),
            'is_active' => true,
        ];
    }
}
