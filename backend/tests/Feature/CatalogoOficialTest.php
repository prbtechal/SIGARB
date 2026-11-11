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

/** Migração 2026_11_20_000100 (catálogo oficial) sobre o estado de produção simulado + data/hora da entrada. */
class CatalogoOficialTest extends TestCase
{
    use RefreshDatabase;

    private function migrar(): void
    {
        (require database_path('migrations/2026_11_20_000100_catalogo_oficial_categorias.php'))->up();
    }

    /** Produção em 2026-11-11: só 004/005 do gestor (001..003 apagadas) + categorias/tipos/materiais da carga. */
    private function producao(): array
    {
        $oficiais = Categoria::whereIn('codigo', array_keys(CatalogoOficial::CATEGORIAS))->whereNotIn('codigo', ['004', '005'])->pluck('id');
        TipoMaterial::whereIn('categoria_id', $oficiais)->delete();
        Categoria::whereIn('id', $oficiais)->delete();
        TipoMaterial::whereIn('categoria_id', Categoria::whereIn('codigo', ['004', '005'])->pluck('id'))->where('nome', '!=', 'Geral')->delete();
        // Cadastro do gestor (outros testes podem ter truncado as tabelas); o nome distinto prova que 004/005 não são alteradas.
        foreach (['004' => ['Treinamento (gestor)', 'TREINAMENTO'], '005' => ['Sinalização', 'EQUIPAMENTO']] as $cod => [$nome, $nat]) {
            $c = Categoria::updateOrCreate(['codigo' => $cod], ['nome' => $nome, 'natureza' => $nat]);
            TipoMaterial::firstOrCreate(['categoria_id' => $c->id, 'nome' => 'Geral'], ['perfil' => 'GERAL']);
        }

        $carga = ['ARMAMENTO' => 'ARMAMENTO', 'TASER' => 'ARMAMENTO', 'MUNICAO' => 'MUNICAO', 'COLETE' => 'EQUIPAMENTO', 'ALGEMA' => 'EQUIPAMENTO', 'COMUNICACAO' => 'EQUIPAMENTO'];
        $tipo = function (string $cat, string $nome, string $perfil) {
            $c = Categoria::firstOrCreate(['codigo' => $cat], ['nome' => $cat, 'natureza' => 'EQUIPAMENTO']);

            return TipoMaterial::firstOrCreate(['categoria_id' => $c->id, 'nome' => $nome], ['perfil' => $perfil]);
        };
        foreach ($carga as $cod => $nat) {
            Categoria::create(['codigo' => $cod, 'nome' => $cod, 'natureza' => $nat]);
            $tipo($cod, 'Geral', 'GERAL');
        }
        $mat = fn (string $cod, string $cat, string $tipoNome, string $perfil, string $modo = 'INDIVIDUAL') => Material::factory()->create([
            'codigo' => $cod, 'modo_controle' => $modo, 'categoria_id' => Categoria::where('codigo', $cat)->value('id'), 'tipo_id' => $tipo($cat, $tipoNome, $perfil)->id,
        ]);

        return [
            'PT-100' => $mat('PT-100-CAL-40-TAURUS', 'ARMAMENTO', 'Pistola', 'ARMA_FOGO'),
            'TASER' => $mat('TASER-X2', 'TASER', 'Dispositivo de energia', 'BAIXA_LETALIDADE'),
            'CARTUCHO' => $mat('CARTUCHO-TASER', 'TASER', 'Acessório de Taser', 'ACESSORIO', 'LOTE'),
            'MUNICAO' => $mat('MUNICOES-CAL-40', 'MUNICAO', 'Geral', 'GERAL', 'LOTE'),
            'COLETE' => $mat('COLETE-BALISTICO-P', 'COLETE', 'Geral', 'GERAL'),
            'ALGEMA' => $mat('ALGEMA-PULSO-ACO', 'ALGEMA', 'Geral', 'GERAL'),
            'RADIO' => $mat('RADIO-HT-DIGITAL-HYTERA', 'COMUNICACAO', 'Geral', 'GERAL'),
        ];
    }

    public function test_migracao_reclassifica_carga_sem_tocar_estoque_e_e_idempotente(): void
    {
        $this->cenario();
        $m = $this->producao();
        $gestor = $this->c['gestorAlfa'];
        $local = $this->c['localAlfa'];
        $this->como($gestor)->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'ENTRADA', 'local_destino_id' => $local->id,
            'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-T-1', 'itens' => [
                ['material_id' => $m['PT-100']->id, 'numero_serie' => 'TST1'],
                ['material_id' => $m['MUNICAO']->id, 'lote_codigo' => 'INV2026', 'quantidade' => 30],
            ]])->assertCreated();
        $estoque = fn () => [ItemIndividual::pluck('material_id', 'id')->all(), Movimentacao::count(), DB::table('movimentacao_itens')->count(),
            DB::table('lotes')->pluck('material_id', 'id')->all(), (int) SaldoEstoque::sum('quantidade')];
        $antes = $estoque();

        $this->migrar();

        $this->assertSame(array_keys(CatalogoOficial::CATEGORIAS), Categoria::whereIn('codigo', array_keys(CatalogoOficial::CATEGORIAS))->orderBy('codigo')->pluck('codigo')->all());
        $this->assertSame(['001' => 'EPI', '002' => 'Comunicação', '003' => 'Contenção', '009' => 'Armas', '010' => 'Equipamento tático'],
            Categoria::whereIn('codigo', ['001', '002', '003', '009', '010'])->orderBy('codigo')->pluck('nome', 'codigo')->all());
        $this->assertSame('Treinamento (gestor)', Categoria::where('codigo', '004')->value('nome'));
        $this->assertSame(0, Categoria::whereIn('codigo', array_keys(CatalogoOficial::CARGA))->count(), 'categorias da carga sem uso são removidas');

        $onde = fn (Material $x) => [$x->fresh()->categoria->codigo, $x->fresh()->tipo->nome, $x->fresh()->tipo->perfil];
        $this->assertSame(['009', 'Arma de fogo (letal)', 'ARMA_FOGO'], $onde($m['PT-100']));
        $this->assertSame(['009', 'Taser ou equipamento semelhante (não letal)', 'BAIXA_LETALIDADE'], $onde($m['TASER']));
        $this->assertSame(['009', 'Acessório de Taser (não letal)', 'ACESSORIO'], $onde($m['CARTUCHO']));
        $this->assertSame(['009', 'Munição letal', 'GERAL'], $onde($m['MUNICAO']));
        $this->assertSame(['001', 'Colete balístico', 'GERAL'], $onde($m['COLETE']));
        $this->assertSame(['003', 'Algemas e acessórios', 'GERAL'], $onde($m['ALGEMA']));
        $this->assertSame(['002', 'Rádio', 'GERAL'], $onde($m['RADIO']));
        $this->assertSame(6, TipoMaterial::where('categoria_id', Categoria::where('codigo', '010')->value('id'))->where('perfil', 'ACESSORIO')->count());
        $this->assertSame($antes, $estoque(), 'itens, lotes, movimentações e saldos intactos');
        $this->assertSame(7, EventoAuditoria::where('acao', 'material.atualizado')->where('dados->motivo', 'catalogo-oficial')->count());

        $snap = fn () => [Categoria::count(), TipoMaterial::count(), Material::pluck('tipo_id', 'id')->all(), EventoAuditoria::count()];
        $s = $snap();
        $this->migrar();
        $this->assertSame($s, $snap(), 'segunda execução não muda nada');
    }

    /** Produção: 580 itens com identificador antigo (prefixo + série). A 000200 regenera no padrão novo e preserva séries e vínculos. */
    public function test_migracao_regenera_identificadores_preservando_series_e_vinculos(): void
    {
        $this->cenario();
        $m = $this->producao();
        $local = $this->c['localAlfa'];
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'ENTRADA', 'local_destino_id' => $local->id,
            'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-T-1', 'itens' => [
                ['material_id' => $m['PT-100']->id, 'numero_serie' => 'SXH-1'],
                ['material_id' => $m['PT-100']->id, 'numero_serie' => '000001'],
                ['material_id' => $m['RADIO']->id, 'numero_serie' => 'HT-9'],
            ]])->assertCreated();
        // Estado legado: identificador = prefixo antigo + série; ainda sem sequência emitida.
        foreach (ItemIndividual::all() as $i) {
            DB::table('itens_individuais')->where('id', $i->id)->update(['identificador' => ($i->numero_serie === 'HT-9' ? 'HT-' : 'ARM-').$i->numero_serie]);
        }
        DB::table('sequencias_identificador')->delete();
        $legado = ItemIndividual::orderBy('id')->get(['id', 'identificador', 'numero_serie', 'material_id', 'local_estoque_id'])->toArray();
        $vinculos = DB::table('movimentacao_itens')->orderBy('id')->get()->toArray();

        $this->migrar();
        (require database_path('migrations/2026_11_20_000200_identificador_automatico_itens.php'))->up();

        $novo = ItemIndividual::orderBy('id')->get(['id', 'identificador', 'numero_serie', 'material_id', 'local_estoque_id'])->toArray();
        $this->assertSame(['ARM-000001', 'ARM-000002', 'COM-000001'], array_column($novo, 'identificador')); // "ARM-000001" antigo não colide
        foreach (['numero_serie', 'material_id', 'local_estoque_id'] as $c) {
            $this->assertSame(array_column($legado, $c), array_column($novo, $c));
        }
        $this->assertEquals($vinculos, DB::table('movimentacao_itens')->orderBy('id')->get()->toArray(), 'razão intacto');
        $mapa = EventoAuditoria::where('acao', 'item.identificador_regenerado')->orderBy('id')->get()->map(fn ($e) => [$e->dados['antigo'], $e->dados['novo']])->all();
        $this->assertSame([['ARM-SXH-1', 'ARM-000001'], ['ARM-000001', 'ARM-000002'], ['HT-HT-9', 'COM-000001']], $mapa);
        $this->assertSame(['ARM' => 2, 'COM' => 1], DB::table('sequencias_identificador')->orderBy('prefixo')->pluck('ultimo', 'prefixo')->map(fn ($v) => (int) $v)->all());

        // Idempotente, e a próxima entrada continua a sequência.
        (require database_path('migrations/2026_11_20_000200_identificador_automatico_itens.php'))->up();
        $this->assertSame(array_column($novo, 'identificador'), ItemIndividual::orderBy('id')->pluck('identificador')->all());
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'ENTRADA', 'local_destino_id' => $local->id,
            'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-T-2', 'itens' => [['material_id' => $m['PT-100']->id, 'numero_serie' => 'NOVA-1']]])->assertCreated();
        $this->assertSame('ARM-000003', ItemIndividual::where('numero_serie', 'NOVA-1')->value('identificador'));
    }

    public function test_entrada_usa_agora_por_padrao_e_aceita_retroativa_com_justificativa(): void
    {
        $this->cenario();
        $gestor = $this->c['gestorAlfa'];
        $post = fn (array $extra, string $lote) => $this->como($gestor)->withHeaders($this->chave())->postJson('/api/v1/movements', $extra + $this->entradaLote($this->c['localAlfa'], 1, $lote));

        $id = $post([], 'L-AGORA')->assertCreated()->json('id');
        $this->assertLessThan(60, abs(Movimentacao::find($id)->ocorrido_em->diffInSeconds(now())));

        $post(['ocorrido_em' => now()->addHour()->toIso8601String()], 'L-FUT')->assertUnprocessable()->assertJsonPath('error.fields.ocorrido_em.0', 'A data/hora da entrada não pode estar no futuro.');
        $post(['ocorrido_em' => '2026-01-02T08:15:00-03:00'], 'L-RET')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['justificativa']]]);
        $this->assertSame(1, Movimentacao::where('tipo', 'ENTRADA')->count(), 'futuro e retroativa sem justificativa são recusados');

        $id = $post(['ocorrido_em' => '2026-01-02T08:15:00-03:00', 'justificativa' => 'Recebida no plantão anterior'], 'L-RET')->assertCreated()->json('id');
        $mov = Movimentacao::find($id);
        $this->assertSame('2026-01-02 11:15:00', $mov->ocorrido_em->utc()->format('Y-m-d H:i:s'));
        $ev = EventoAuditoria::where('acao', 'estoque.entrada')->where('recurso_id', $id)->firstOrFail();
        $this->assertTrue($ev->dados['retroativa']);
    }
}
