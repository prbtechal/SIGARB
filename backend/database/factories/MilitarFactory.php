<?php

namespace Database\Factories;

use App\Models\Militar;
use App\Models\PostoGraduacao;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

class MilitarFactory extends Factory
{
    protected $model = Militar::class;

    public function definition(): array
    {
        return [
            'unidade_id' => Unidade::factory(),
            'posto_graduacao_id' => fn () => PostoGraduacao::firstOrCreate(['sigla' => 'SD'], ['nome' => 'Soldado', 'ordem' => 1])->id,
            'nome' => 'Pessoa Fictícia '.$this->faker->unique()->numberBetween(1, 999999),
            'nome_guerra' => 'FICT'.$this->faker->unique()->numberBetween(1, 99999),
            'matricula' => 'T-'.$this->faker->unique()->numerify('########'),
            'categoria_efetivo' => 'PM_ATIVA',
        ];
    }
}
