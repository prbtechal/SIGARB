<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return ['nome' => 'Usuário Fictício '.$this->faker->unique()->numberBetween(1, 999999), 'login' => 'u'.$this->faker->unique()->numerify('######'), 'password' => 'senha-teste-1234', 'ativo' => true];
    }
}
