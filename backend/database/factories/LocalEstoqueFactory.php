<?php

namespace Database\Factories;

use App\Models\LocalEstoque;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocalEstoqueFactory extends Factory
{
    protected $model = LocalEstoque::class;

    public function definition(): array
    {
        return ['unidade_id' => Unidade::factory(), 'codigo' => 'LOC-'.strtoupper($this->faker->unique()->bothify('??###')), 'nome' => 'Local fictício', 'tipo' => 'RESERVA'];
    }
}
