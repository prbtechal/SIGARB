<?php

namespace Database\Factories;

use App\Models\Organizacao;
use Illuminate\Database\Eloquent\Factories\Factory;

class UnidadeFactory extends Factory
{
    protected $model = \App\Models\Unidade::class;

    public function definition(): array
    {
        return ['organizacao_id' => Organizacao::factory(), 'sigla' => 'UN-'.strtoupper($this->faker->unique()->bothify('??###')), 'nome' => 'Unidade fictícia', 'tipo' => 'UNIDADE'];
    }

    public function sub(\App\Models\Unidade $sup): static
    {
        return $this->state(['organizacao_id' => $sup->organizacao_id, 'unidade_superior_id' => $sup->id, 'tipo' => 'SUBUNIDADE']);
    }
}
