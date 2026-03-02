<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Article>
 */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->name(),
            'content' => fake()->realText(1000),
            'status' => fake()->numberBetween(0, 2),
            'password' => null,
        ];
    }
}
