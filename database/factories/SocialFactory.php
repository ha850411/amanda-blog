<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Social>
 */
class SocialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'icon' => 'fa-brands fa-facebook',
            'url' => fake()->url(),
            'status' => fake()->numberBetween(0, 1),
        ];
    }
}
