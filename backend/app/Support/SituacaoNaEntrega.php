<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Decisão do responsável (2026-11-18): situação do material RELATIVA À ENTREGA, igual para item individual e lote, em todas as telas
 * que mostram material de uma entrega. Calculada pelo vínculo devolução ↔ entrega (vinculo_id / vinculo_parcial_id), nunca pela
 * situação atual do item no estoque — o item devolvido e reentregue a outro efetivo continua "Devolvido" na entrega antiga.
 *
 *   ENTREGUE                — ainda com o efetivo
 *   DEVOLVIDO               — voltou ao estoque por devolução ligada a esta entrega
 *   DEVOLVIDO_PARCIAL       — lote: "Devolvido parcialmente: X de Y"
 *   CONSUMIDO               — entrega sem retorno
 *   BAIXADO_APOS_DEVOLUCAO  — item voltou e foi baixado antes de sair de novo
 *
 * A situação atual do item no estoque, quando exposta, é só informação secundária (`hoje`, ex.: "hoje: disponível na RES-POCO").
 */
final class SituacaoNaEntrega
{
    /**
     * @return array{codigo: string, rotulo: string, entregue: int, devolvido: int, pendente: int, hoje?: string|null}
     */
    public static function dto(string $codigo, int $entregue, int $devolvido, ?string $hoje = null): array
    {
        $rotulo = match ($codigo) {
            'ENTREGUE' => 'Entregue',
            'DEVOLVIDO' => 'Devolvido',
            'DEVOLVIDO_PARCIAL' => 'Devolvido parcialmente: '.self::n($devolvido).' de '.self::n($entregue),
            'CONSUMIDO' => 'Consumido',
            'BAIXADO_APOS_DEVOLUCAO' => 'Baixado após devolução',
        };

        return ['codigo' => $codigo, 'rotulo' => $rotulo, 'entregue' => $entregue, 'devolvido' => $devolvido,
            'pendente' => $codigo === 'CONSUMIDO' ? 0 : max(0, $entregue - $devolvido)] + ($hoje !== null ? ['hoje' => $hoje] : []);
    }

    /** Linha ainda com o efetivo (telas Entrega de material, Devolução e Material comigo): Entregue ou Devolvido parcialmente. */
    public static function pendente(int $entregue, int $devolvido): array
    {
        return self::dto($devolvido > 0 ? 'DEVOLVIDO_PARCIAL' : 'ENTREGUE', $entregue, $devolvido);
    }

    /**
     * Situação de cada item e lote de uma entrega.
     *
     * @return array{itens: array<int, array>, lotes: array<int, array>}
     */
    public static function daEntrega(int $entregaId): array
    {
        $e = DB::table('movimentacoes')->where('id', $entregaId)->first(['id', 'tipo', 'previsao_retorno']);
        if (! $e || $e->tipo !== 'ENTREGA') {
            return ['itens' => [], 'lotes' => []];
        }
        // Acautelamento: sem previsão de retorno, mas volta pelo desacautelamento (DEVOLUCAO com vinculo_id = entrega).
        $comRetorno = $e->previsao_retorno !== null || DB::table('acautelamentos')->where('entrega_id', $e->id)->exists();
        $devolucoes = DB::table('movimentacoes')->where('tipo', 'DEVOLUCAO')
            ->where(fn ($w) => $w->where('vinculo_id', $e->id)->orWhere('vinculo_parcial_id', $e->id))->pluck('id');
        $itensEntrega = DB::table('movimentacao_itens')->where('movimentacao_id', $e->id)->get(['item_individual_id', 'lote_id', 'quantidade']);
        $devolvidos = $devolucoes->isEmpty() ? collect() : DB::table('movimentacao_itens')->whereIn('movimentacao_id', $devolucoes)->get(['movimentacao_id', 'item_individual_id', 'lote_id', 'quantidade']);

        $out = ['itens' => [], 'lotes' => []];
        $ids = $itensEntrega->pluck('item_individual_id')->filter()->all();
        $atual = $ids === [] ? collect() : DB::table('itens_individuais as i')->leftJoin('locais_estoque as l', 'l.id', '=', 'i.local_estoque_id')
            ->whereIn('i.id', $ids)->get(['i.id', 'i.situacao', 'l.codigo'])->keyBy('id');
        foreach ($itensEntrega->whereNotNull('item_individual_id') as $mi) {
            $id = (int) $mi->item_individual_id;
            $dev = $devolvidos->firstWhere('item_individual_id', $id);
            if ($dev) {
                $codigo = self::baixadoDepois($id, (int) $dev->movimentacao_id) ? 'BAIXADO_APOS_DEVOLUCAO' : 'DEVOLVIDO';
                $out['itens'][$id] = self::dto($codigo, 1, 1, self::hoje($atual->get($id)));
            } else {
                $out['itens'][$id] = self::dto($comRetorno ? 'ENTREGUE' : 'CONSUMIDO', 1, 0);
            }
        }
        $porLote = $itensEntrega->whereNotNull('lote_id')->groupBy('lote_id');
        foreach ($porLote as $lote => $linhas) {
            $q = (int) $linhas->sum('quantidade');
            $d = min($q, (int) $devolvidos->where('lote_id', (int) $lote)->sum('quantidade'));
            $codigo = ! $comRetorno ? 'CONSUMIDO' : ($d >= $q ? 'DEVOLVIDO' : ($d > 0 ? 'DEVOLVIDO_PARCIAL' : 'ENTREGUE'));
            $out['lotes'][(int) $lote] = self::dto($codigo, $q, $d);
        }

        return $out;
    }

    /** O item foi baixado depois desta devolução sem ter sido entregue de novo no meio. */
    private static function baixadoDepois(int $item, int $devolucaoId): bool
    {
        $seguintes = DB::table('movimentacoes as m')->join('movimentacao_itens as mi', 'mi.movimentacao_id', '=', 'm.id')
            ->where('mi.item_individual_id', $item)->where('m.id', '>', $devolucaoId)->whereIn('m.tipo', ['BAIXA', 'ENTREGA'])
            ->orderBy('m.id')->value('m.tipo');

        return $seguintes === 'BAIXA';
    }

    private static function hoje(?object $i): ?string
    {
        if (! $i) {
            return null;
        }
        $s = match ($i->situacao) {
            'DISPONIVEL' => 'disponível', 'RESERVADO' => 'reservado', 'MANUTENCAO' => 'em manutenção', 'ENTREGUE' => 'entregue em outra entrega',
            'ACAUTELADO' => 'acautelado', 'BAIXADO' => 'baixado', 'EM_TRANSITO' => 'em trânsito', default => mb_strtolower(str_replace('_', ' ', (string) $i->situacao)),
        };

        return in_array($i->situacao, ['DISPONIVEL', 'RESERVADO', 'MANUTENCAO'], true) && $i->codigo ? "hoje: $s na {$i->codigo}" : "hoje: $s";
    }

    private static function n(int $v): string
    {
        return number_format($v, 0, ',', '.');
    }
}
