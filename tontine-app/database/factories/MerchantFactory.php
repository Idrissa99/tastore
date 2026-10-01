<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantFactory extends Factory
{
    protected $model = \App\Models\Merchant::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->merchant(),
            'business_name' => fake()->company(),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Niamey', 'Maradi', 'Zinder', 'Tahoua']),
            'description' => fake()->sentence(12),
            'status' => 'approved',
            'rating' => fake()->randomFloat(2, 3, 5),
        ];
    }
}
