<?php

namespace App\Http\Controllers\Api;

use App\Models\Acautelamento;
use App\Models\Inventario;
use App\Models\Movimentacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RF-022 / RN-023 — alertas internos calculados na consulta, no escopo de quem pergunta.
 * Prazos PROVISÓRIOS em config/sigarb.php (alertas.*); sem persistência nem resolução (regras oficiais: P-03).
 */
class AlertaController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $hoje = now()->toDateString();
        $alertas = [];
        $add = function (string $tipo, string $sev, string $titulo, int $n, string $link) use (&$alertas) {
            if ($n > 0) {
                $alertas[] = compact('tipo', 'titulo', 'link') + ['severidade' => $sev, 'quantidade' => $n];
            }
        };
        $unidades = fn (string $perm) => $this->authz()->unidadesPermitidas($u, $perm);
        $escopo = function ($q, string $perm, string $col) use ($unidades) {
            $ids = $unidades($perm);

            return $ids === null ? $q : $q->whereIn($col, $ids ?: [0]);
        };

        if ($this->authz()->pode($u, 'estoque.ver')) {
            // CP-12: validade de item individual (coletes). Só informa; vencer não muda a situação do item (sem regra aprovada).
            [, $limite] = EstoqueController::janelaValidade();
            $itens = fn () => $escopo(DB::table('itens_individuais as i')->join('locais_estoque as le', 'le.id', '=', 'i.local_estoque_id')
                ->whereNotNull('i.validade')->whereNotIn('i.situacao', ['BAIXADO', 'ESTORNADO', 'FORA_DO_ESTOQUE']), 'estoque.ver', 'le.unidade_id');
            $add('ITEM_VENCIDO', 'perigo', 'Coletes/itens com validade vencida', $itens()->where('i.validade', '<', $hoje)->count(), '/estoque/itens?validade=VENCIDO');
            $add('ITEM_VENCENDO', 'alerta', 'Coletes/itens vencendo em até '.config('sigarb.alertas.validade_item_dias').' dias', $itens()->whereBetween('i.validade', [$hoje, $limite])->count(), '/estoque/itens?validade=VENCENDO');
            $lotes = fn () => $escopo(DB::table('saldos_estoque as s')->join('lotes as l', 'l.id', '=', 's.lote_id')
                ->join('locais_estoque as le', 'le.id', '=', 's.local_estoque_id')->where('s.quantidade', '>', 0)->whereNotNull('l.validade'), 'estoque.ver', 'le.unidade_id');
            $add('LOTE_VENCIDO', 'perigo', 'Lotes vencidos com saldo', $lotes()->where('l.validade', '<', $hoje)->count(), '/estoque');
            $add('LOTE_VENCENDO', 'alerta', 'Lotes vencendo em até '.config('sigarb.alertas.validade_dias').' dias', $lotes()->whereBetween('l.validade', [$hoje, now()->addDays(config('sigarb.alertas.validade_dias'))->toDateString()])->count(), '/estoque');
            $ativos = fn () => $escopo(Acautelamento::where('estado', 'ATIVO'), 'estoque.ver', 'unidade_id');
            $add('INSPECAO_ATRASADA', 'perigo', 'Inspeções atrasadas', $ativos()->where('proxima_inspecao', '<', $hoje)->count(), '/acautelamentos?inspecao_vencendo=1');
            $add('INSPECAO_VENCENDO', 'alerta', 'Inspeções vencendo em até '.config('sigarb.acautelamento.alerta_inspecao_dias').' dias', $ativos()->whereBetween('proxima_inspecao', [$hoje, now()->addDays(config('sigarb.acautelamento.alerta_inspecao_dias'))->toDateString()])->count(), '/acautelamentos?inspecao_vencendo=1');
            $locais = fn (string $perm) => $escopo(DB::table('locais_estoque')->select('id'), $perm, 'unidade_id');
            $add('RETORNO_ATRASADO', 'perigo', 'Entregas com retorno vencido', Movimentacao::where('tipo', 'ENTREGA')->whereNotNull('previsao_retorno')->where('previsao_retorno', '<', $hoje)
                ->whereDoesntHave('conclusao')->whereIn('local_origem_id', $locais('estoque.ver'))->count(), '/operacoes');
        }
        if ($this->authz()->pode($u, 'estoque.transferir')) {
            $add('TRANSFERENCIA_A_RECEBER', 'info', 'Transferências a receber', Movimentacao::where('tipo', 'TRANSFERENCIA')->whereDoesntHave('conclusao')
                ->whereIn('local_destino_id', $escopo(DB::table('locais_estoque')->select('id'), 'estoque.transferir', 'unidade_id'))->count(), '/operacoes');
        }
        // shortcut: papel de autoaprovação em qualquer unidade libera a contagem dos próprios pedidos; o escopo por unidade continua no $escopo.
        $auto = DB::table('atribuicoes_papel as a')->join('papeis as p', 'p.id', '=', 'a.papel_id')->where('a.usuario_id', $u->id)
            ->whereIn('p.codigo', config('sigarb.politicas.autoaprovacao_papeis'))
            ->where(fn ($q) => $q->whereNull('a.expira_em')->orWhere('a.expira_em', '>', now()))->exists();
        if ($this->authz()->pode($u, 'acautelamento.aprovar')) {
            $add('ACAUTELAMENTO_PENDENTE', 'alerta', 'Acautelamentos aguardando decisão', $escopo(Acautelamento::where('estado', 'SOLICITADO')->when(! $auto, fn ($q) => $q->where('solicitante_id', '!=', $u->id)), 'acautelamento.aprovar', 'unidade_id')->count(), '/acautelamentos');
        }
        if ($this->authz()->pode($u, 'inventario.aprovar')) {
            $add('INVENTARIO_PENDENTE', 'alerta', 'Inventários aguardando aprovação de ajuste', $escopo(Inventario::where('estado', 'AGUARDANDO_APROVACAO')->when(! $auto, fn ($q) => $q->where('aberto_por', '!=', $u->id)->where('contado_por', '!=', $u->id)), 'inventario.aprovar', 'unidade_id')->count(), '/inventarios');
        }

        return response()->json(['data' => $alertas, 'regra' => 'PROVISORIA', 'gerado_em' => now()->toIso8601String()]);
    }
}
