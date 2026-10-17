<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class collegeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'college_ar' => fake('ar_SA')->unique()->word(),
            'college_en' => fake()->unique()->word(),
        ];
    }
}
