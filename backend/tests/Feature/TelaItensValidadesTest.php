<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** "Itens e validades" é tela de gestores para cima (2026-11-18): permissão de tela própria, fora do ARMEIRO e do OPERADOR. */
class TelaItensValidadesTest extends TestCase
{
    use RefreshDatabase;

    private function papeisCom(string $perm): array
    {
        return DB::table('papel_permissao as pp')->join('papeis as p', 'p.id', '=', 'pp.papel_id')->join('permissoes as pe', 'pe.id', '=', 'pp.permissao_id')
            ->where('pe.codigo', $perm)->orderBy('p.codigo')->pluck('p.codigo')->all();
    }

    public function test_tela_itens_so_para_gestores_para_cima_e_lista_de_itens_segue_para_o_armeiro(): void
    {
        $this->cenario();
        $this->assertSame(['APROVADOR', 'AUDITOR', 'GESTOR'], $this->papeisCom('estoque.itens'));
        $this->assertContains('ARMEIRO', $this->papeisCom('estoque.ver'), 'a lista de itens da entrega/devolução continua por estoque.ver');
    }
}
