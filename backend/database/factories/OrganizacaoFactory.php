<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizacaoFactory extends Factory
{
    protected $model = \App\Models\Organizacao::class;

    public function definition(): array
    {
        return ['sigla' => 'ORG-'.strtoupper($this->faker->unique()->bothify('??##')), 'nome' => 'Organização fictícia '.$this->faker->unique()->numberBetween(1, 99999)];
    }
}
