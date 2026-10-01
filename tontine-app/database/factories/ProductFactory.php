<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = \App\Models\Product::class;

    public function definition(): array
    {
        $categories = ['Téléphones', 'Électroménager', 'Meubles', 'Motos', 'Panneaux solaires', 'Matériel agricole'];

        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement($categories),
            'price' => fake()->numberBetween(20000, 800000),
            'stock' => fake()->numberBetween(1, 50),
            'image' => null,
            'status' => 'published',
        ];
    }
}
