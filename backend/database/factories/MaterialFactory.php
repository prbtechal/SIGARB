<?php

namespace Database\Factories;

use App\Models\Categoria;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaterialFactory extends Factory
{
    protected $model = \App\Models\Material::class;

    public function definition(): array
    {
        return [
            'categoria_id' => fn () => Categoria::firstOrCreate(['codigo' => 'CAT-TESTE'], ['nome' => 'Categoria teste', 'natureza' => 'EQUIPAMENTO'])->id,
            'codigo' => 'MAT-'.strtoupper($this->faker->unique()->bothify('??####')),
            'nome' => 'Material fictício', 'modo_controle' => 'INDIVIDUAL',
        ];
    }

    public function lote(): static { return $this->state(['modo_controle' => 'LOTE']); }
}
