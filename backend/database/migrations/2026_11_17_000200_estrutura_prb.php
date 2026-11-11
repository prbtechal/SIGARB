<?php

use App\Models\Organizacao;
use App\Models\Unidade;
use Illuminate\Database\Migrations\Migration;

/**
 * Estrutura do Programa Ronda no Bairro, igual à do Sistema de Escalas: Perímetro (cidade) → Base → Unidade.
 * Só estrutura (nenhuma pessoa). Idempotente: não duplica o que já existe. Locais de estoque são cadastrados pela tela.
 */
return new class extends Migration
{
    public const ESTRUTURA = [
        'Maceió' => ['Poço' => ['Orla', 'Centro', 'Jacintinho'], 'Benedito' => []],
        'Marechal Deodoro' => ['Francês' => []],
        'Arapiraca' => [], 'Paripueira' => [], 'Rio Largo' => [],
    ];

    public function up(): void
    {
        $org = Organizacao::firstOrCreate(['sigla' => 'PRB'], ['nome' => 'Programa Ronda no Bairro']);
        $criar = function (string $nome, ?Unidade $sup) use ($org) {
            // Sigla única na organização: "MACEIO", "MACEIO-POCO", "MACEIO-POCO-ORLA".
            $sigla = strtoupper(trim(($sup ? $sup->sigla.'-' : '').preg_replace('/[^A-Za-z0-9]+/', '-', \Illuminate\Support\Str::ascii($nome)), '-'));
            return Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => substr($sigla, 0, 30)],
                ['nome' => $nome, 'tipo' => $sup ? 'SUBUNIDADE' : 'UNIDADE', 'unidade_superior_id' => $sup?->id]);
        };
        foreach (self::ESTRUTURA as $perimetro => $bases) {
            $p = $criar($perimetro, null);
            foreach ($bases as $base => $unidades) {
                $b = $criar($base, $p);
                foreach ($unidades as $un) {
                    $criar($un, $b);
                }
            }
        }
    }

    public function down(): void
    {
        // Não remove: unidades podem já ter efetivo, locais e movimentações (restrictOnDelete).
    }
};
