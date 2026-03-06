<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Visit>
 */
class VisitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ip' => fake()->ipv4(),
            'date' => fake()->dateTimeThisYear(),
        ];
    }
}
