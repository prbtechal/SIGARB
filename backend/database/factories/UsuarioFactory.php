<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioFactory extends Factory
{
    protected $model = \App\Models\Usuario::class;

    public function definition(): array
    {
        return ['nome' => 'Usuário Fictício '.$this->faker->unique()->numberBetween(1, 999999), 'login' => 'u'.$this->faker->unique()->numerify('######'), 'password' => 'senha-teste-1234', 'ativo' => true];
    }
}
