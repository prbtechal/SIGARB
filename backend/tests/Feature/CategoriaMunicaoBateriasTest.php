<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\EventoAuditoria;
use App\Models\ItemIndividual;
use App\Models\Material;
use App\Models\Movimentacao;
use App\Models\SaldoEstoque;
use App\Models\TipoMaterial;
use App\Support\CatalogoOficial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Migração 2026_11_22_000100: munição para 011 Munição e baterias para 010 Equipamento tático, sobre o estado de produção simulado. */
class CategoriaMunicaoBateriasTest extends TestCase
{
    use RefreshDatabase;

    private function migrar(): void
    {
        (require database_path('migrations/2026_11_22_000100_categoria_municao_e_baterias.php'))->up();
    }

    /** Produção em 2026-11-16: sem 011; munição e baterias em 009 Armas (catálogo de 2026-11-11). */
    private function producao(bool $municaoDoGestor = false): array
    {
        $c011 = Categoria::where('codigo', '011')->first();
        if ($c011) {
            TipoMaterial::where('categoria_id', $c011->id)->delete();
            $c011->delete();
        }
        $armas = Categoria::firstOrCreate(['codigo' => '009'], ['nome' => 'Armas', 'natureza' => 'ARMAMENTO']);
        Categoria::firstOrCreate(['codigo' => '010'], ['nome' => 'Equipamento tático', 'natureza' => 'EQUIPAMENTO']);
        $tipo = fn (string $nome, string $perfil) => TipoMaterial::firstOrCreate(['categoria_id' => $armas->id, 'nome' => $nome], ['perfil' => $perfil]);
        $mat = fn (string $cod, string $nome, TipoMaterial $t, string $modo) => Material::factory()->create([
            'codigo' => $cod, 'nome' => $nome, 'modo_controle' => $modo, 'categoria_id' => $armas->id, 'tipo_id' => $t->id,
        ]);
        if ($municaoDoGestor) {
            Categoria::create(['codigo' => 'MUN-G', 'nome' => 'Munições (gestor)', 'natureza' => 'MUNICAO']);
        }

        return [
            'PT-100' => $mat('PT-100-CAL-40-TAURUS', 'PT 100 Cal. .40 Taurus', $tipo('Arma de fogo (letal)', 'ARMA_FOGO'), 'INDIVIDUAL'),
            'MUNICAO' => $mat('MUNICOES-CAL-40', 'Munições Cal. .40', $tipo('Munição letal', 'GERAL'), 'LOTE'),
            'BATERIA' => $mat('BATERIAS-APPM', 'Baterias APPM, com desligamento automático', $tipo('Acessório de Taser (não letal)', 'ACESSORIO'), 'LOTE'),
            'CARTUCHO' => $mat('CARTUCHOS-X2', 'Cartuchos Inteligentes X2 NS', $tipo('Acessório de Taser (não letal)', 'ACESSORIO'), 'LOTE'),
        ];
    }

    public function test_move_municao_e_baterias_sem_tocar_estoque_e_e_idempotente(): void
    {
        $this->cenario();
        $m = $this->producao();
        $local = $this->c['localAlfa'];
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'ENTRADA', 'local_destino_id' => $local->id,
            'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-T-1', 'itens' => [
                ['material_id' => $m['PT-100']->id, 'numero_serie' => 'TST1'],
                ['material_id' => $m['MUNICAO']->id, 'lote_codigo' => 'INV2026', 'quantidade' => 30],
                ['material_id' => $m['BATERIA']->id, 'lote_codigo' => 'INV2026', 'quantidade' => 20],
            ]])->assertCreated();
        $estoque = fn () => [ItemIndividual::pluck('identificador', 'id')->all(), Movimentacao::count(), DB::table('movimentacao_itens')->count(),
            DB::table('lotes')->pluck('material_id', 'id')->all(), (int) SaldoEstoque::sum('quantidade')];
        $antes = $estoque();

        $this->migrar();

        $mun = Categoria::where('codigo', '011')->firstOrFail();
        $this->assertSame(['Munição', 'MUNICAO', 'MUN'], [$mun->nome, $mun->natureza, $mun->prefixo_codigo]);
        $onde = fn (Material $x) => [$x->fresh()->categoria->codigo, $x->fresh()->tipo->nome, $x->fresh()->tipo->perfil];
        $this->assertSame(['011', 'Munição letal', 'GERAL'], $onde($m['MUNICAO']));
        $this->assertSame(['010', 'Bateria', 'ACESSORIO'], $onde($m['BATERIA']));
        $this->assertSame(['009', 'Acessório de Taser (não letal)', 'ACESSORIO'], $onde($m['CARTUCHO']), 'cartucho continua acessório de Taser');
        $this->assertSame(['009', 'Arma de fogo (letal)', 'ARMA_FOGO'], $onde($m['PT-100']));
        $this->assertSame(['Cartucho de Taser (não letal)', 'Geral', 'Munição de baixa letalidade', 'Munição letal'], TipoMaterial::where('categoria_id', $mun->id)->orderBy('nome')->pluck('nome')->all());
        $this->assertSame(0, TipoMaterial::where('categoria_id', Categoria::where('codigo', '009')->value('id'))->whereIn('nome', CatalogoOficial::TIPOS_MUNICAO)->count());
        $this->assertSame($antes, $estoque(), 'itens (e seus identificadores), lotes, movimentações e saldos intactos');
        $this->assertSame(1, EventoAuditoria::where('acao', 'material.atualizado')->where('dados->motivo', 'categoria-municao')->count());
        $this->assertSame(1, EventoAuditoria::where('acao', 'material.atualizado')->where('dados->motivo', 'baterias-equipamento')->count());

        $snap = fn () => [Categoria::count(), TipoMaterial::count(), Material::pluck('tipo_id', 'id')->all(), EventoAuditoria::count()];
        $s = $snap();
        $this->migrar();
        $this->assertSame($s, $snap(), 'segunda execução não muda nada');
    }

    public function test_adota_categoria_de_municao_do_gestor_como_011(): void
    {
        $this->cenario();
        $m = $this->producao(municaoDoGestor: true);

        $this->migrar();

        $mun = Categoria::where('codigo', '011')->firstOrFail();
        $this->assertSame('Munições (gestor)', $mun->nome, 'nome do gestor mantido');
        $this->assertSame(0, Categoria::where('codigo', 'MUN-G')->count());
        $this->assertSame(1, Categoria::where('natureza', 'MUNICAO')->count());
        $this->assertSame('011', $m['MUNICAO']->fresh()->categoria->codigo);
    }

    public function test_cartucho_de_taser_vai_para_municao_e_e_idempotente(): void
    {
        $this->cenario();
        $m = $this->producao();
        $this->migrar();
        $antes = [ItemIndividual::count(), DB::table('lotes')->pluck('material_id', 'id')->all()];

        (require database_path('migrations/2026_11_23_000100_cartuchos_taser_municao.php'))->up();

        $onde = fn (Material $x) => [$x->fresh()->categoria->codigo, $x->fresh()->tipo->nome, $x->fresh()->tipo->perfil];
        $this->assertSame(['011', 'Cartucho de Taser (não letal)', 'ACESSORIO'], $onde($m['CARTUCHO']));
        $this->assertSame(['010', 'Bateria', 'ACESSORIO'], $onde($m['BATERIA']));
        $this->assertSame('MUNICAO', $m['CARTUCHO']->fresh()->categoria->natureza, 'continua restrito a quem pode material letal');
        $this->assertSame($antes, [ItemIndividual::count(), DB::table('lotes')->pluck('material_id', 'id')->all()]);
        $this->assertSame(1, EventoAuditoria::where('acao', 'material.atualizado')->where('dados->motivo', 'cartuchos-municao')->count());

        $n = EventoAuditoria::count();
        (require database_path('migrations/2026_11_23_000100_cartuchos_taser_municao.php'))->up();
        $this->assertSame($n, EventoAuditoria::count(), 'segunda execução não muda nada');
    }

    public function test_reconhece_bateria_pelo_nome(): void
    {
        $this->assertTrue(CatalogoOficial::ehBateria('Baterias APPM, com desligamento automático'));
        $this->assertTrue(CatalogoOficial::ehBateria('bateria para rádio'));
        $this->assertFalse(CatalogoOficial::ehBateria('Cartuchos Inteligentes X2 NS'));
        $this->assertFalse(CatalogoOficial::ehBateria('Carregador de bateria'));
        $this->assertTrue(CatalogoOficial::ehCartucho('Cartuchos Inteligentes X2 NS'));
        $this->assertFalse(CatalogoOficial::ehCartucho('Porta-cartucho'));
    }
}
