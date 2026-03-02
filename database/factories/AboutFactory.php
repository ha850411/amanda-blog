<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\About>
 */
class AboutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->name(),
            'sub_title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'picture' => '/images/about.jpg',
        ];
    }
}
