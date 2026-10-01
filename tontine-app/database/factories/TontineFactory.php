<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TontineFactory extends Factory
{
    protected $model = \App\Models\Tontine::class;

    public function definition(): array
    {
        $product = Product::factory();

        return [
            'product_id' => $product,
            'created_by' => User::factory(),
            'name' => 'Tontine ' . fake()->words(2, true),
            'total_amount' => fake()->numberBetween(50000, 500000),
            'contribution_amount' => fake()->numberBetween(5000, 25000),
            'frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'max_members' => fake()->numberBetween(3, 8),
            'status' => 'open',
            'current_round' => 1,
            'start_date' => now()->addDays(3),
        ];
    }

    public function full(): static
    {
        return $this->state(fn (array $attrs) => ['max_members' => $attrs['max_members'] ?? 4]);
    }
}
