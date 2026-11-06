<?php

namespace App\Http\Controllers\Api;

use App\Models\Acautelamento;
use App\Models\Movimentacao;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RF-023 provisório: exportação CSV (abre no Excel; PDF pela impressão do navegador) das consultas já autorizadas.
 * Não são relatórios oficiais (modelos, campos e assinaturas: P-12). Escopo aplicado no servidor; exportação auditada.
 */
class RelatorioController extends ApiController
{
    public const CHAVES = ['saldos' => 'Saldos de estoque', 'movimentacoes' => 'Movimentações', 'acautelamentos' => 'Acautelamentos'];

    private const LIMITE = 10000;

    public function index(): JsonResponse
    {
        $this->exigir('relatorios.exportar');

        return response()->json(['data' => collect(self::CHAVES)->map(fn ($n, $k) => ['chave' => $k, 'nome' => $n])->values(), 'regra' => 'PROVISORIA']);
    }

    public function exportar(Request $r, string $chave): StreamedResponse
    {
        if (! isset(self::CHAVES[$chave])) {
            throw new SigarbException('RELATORIO_INEXISTENTE', 'Relatório inexistente.', 404);
        }
        $this->exigir('relatorios.exportar');
        $this->exigir('estoque.ver');
        $f = $r->validate(['de' => ['nullable', 'date'], 'ate' => ['nullable', 'date'], 'local_id' => ['nullable', 'integer']]);
        [$cab, $linhas] = match ($chave) {
            'saldos' => $this->saldos($r, $f),
            'movimentacoes' => $this->movimentacoes($r, $f),
            'acautelamentos' => $this->acautelamentos($r),
        };
        $this->auditoria()->registrar('relatorio.exportado', 'SUCESSO', dados: ['relatorio' => $chave, 'filtros' => $f]);
        $nome = "sigarb-$chave-".now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($cab, $linhas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos corretos no Excel
            fputcsv($out, $cab, ';');
            $n = 0;
            foreach ($linhas as $l) {
                if (++$n > self::LIMITE) {
                    fputcsv($out, ['(limite de '.self::LIMITE.' linhas atingido; refine os filtros)'], ';');
                    break;
                }
                fputcsv($out, array_map([self::class, 'celula'], $l), ';');
            }
            fclose($out);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Neutraliza injeção de fórmula em planilhas (=, +, -, @, tab, CR no início). */
    public static function celula(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }

    private function saldos(Request $r, array $f): array
    {
        $q = app(EstoqueController::class)->consultaSaldos($r, array_filter(['local_id' => $f['local_id'] ?? null]))
            ->orderBy('m.nome')->orderBy('le.codigo');

        return [['Unidade', 'Local', 'Material', 'Código', 'Controle', 'Lote', 'Validade', 'Quantidade', 'Reservada', 'Disponível'],
            $q->cursor()->map(fn ($s) => [$s->unidade_sigla, $s->local_codigo, $s->material_nome, $s->material_codigo, $s->modo_controle, $s->lote_codigo, $s->validade, $s->quantidade, $s->quantidade_reservada, $s->disponivel])];
    }

    private function movimentacoes(Request $r, array $f): array
    {
        $locais = $this->authz()->escopar(DB::table('locais_estoque')->select('id'), $r->user(), 'estoque.ver');
        $q = Movimentacao::with(['responsavel:id,nome', 'localOrigem:id,codigo', 'localDestino:id,codigo', 'militar:id,nome_guerra'])->withCount('itens')
            ->where(fn ($w) => $w->whereIn('local_origem_id', $locais)->orWhereIn('local_destino_id', $locais))->orderBy('ocorrido_em');
        if (! empty($f['de'])) {
            $q->where('ocorrido_em', '>=', $f['de']);
        }
        if (! empty($f['ate'])) {
            $q->where('ocorrido_em', '<', date('Y-m-d', strtotime($f['ate'].' +1 day')));
        }
        if (! empty($f['local_id'])) {
            $q->where(fn ($w) => $w->where('local_origem_id', $f['local_id'])->orWhere('local_destino_id', $f['local_id']));
        }

        return [['Número', 'Data/hora', 'Tipo', 'Origem', 'Destino', 'Efetivo', 'Responsável', 'Documento', 'Itens', 'Regra provisória'],
            $q->cursor()->map(fn ($m) => [$m->id, $m->ocorrido_em->format('Y-m-d H:i:s'), $m->tipo, $m->localOrigem?->codigo, $m->localDestino?->codigo, $m->militar?->nome_guerra, $m->responsavel->nome, $m->documento_referencia, $m->itens_count, $m->provisoria ? 'sim' : 'não'])];
    }

    private function acautelamentos(Request $r): array
    {
        $q = Acautelamento::with(['militar:id,nome_guerra', 'item:id,identificador', 'unidade:id,sigla'])->orderBy('id');
        $this->authz()->escopar($q, $r->user(), 'estoque.ver');

        return [['Código', 'Unidade', 'Efetivo', 'Item', 'Situação', 'Próxima inspeção', 'Criado em'],
            $q->cursor()->map(fn ($a) => [$a->codigo, $a->unidade->sigla, $a->militar->nome_guerra, $a->item->identificador, $a->estado, $a->proxima_inspecao?->format('Y-m-d'), $a->created_at->format('Y-m-d H:i')])];
    }
}
