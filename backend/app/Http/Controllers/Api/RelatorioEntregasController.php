<?php

namespace App\Http\Controllers\Api;

use App\Support\Planilha;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Relatório do armeiro (decisão do responsável, 2026-11-18): quantidade entregue e devolvida na base, por dia, semana ou mês,
 * separada em ARMAS (tipo de material com perfil ARMA_FOGO), MUNIÇÃO (natureza MUNICAO) e DEMAIS materiais.
 * Conta as movimentações ENTREGA (com efetivo; inclui a entrega de acautelamento) e DEVOLUCAO (inclui desacautelamento):
 * item individual = 1, lote = quantidade. Escopo: unidade do local da movimentação, com `relatorios.entregas`. Exportação auditada.
 */
class RelatorioEntregasController extends ApiController
{
    public const FUSO = 'America/Maceio';

    private const CLASSES = ['armas' => 'Armas', 'municao' => 'Munição', 'demais' => 'Demais materiais'];

    private const MAX_DIAS = 400;

    public function index(Request $r): JsonResponse
    {
        [$f, $series, $totais] = $this->dados($r);

        return response()->json(['de' => $f['de'], 'ate' => $f['ate'], 'agrupar' => $f['agrupar'], 'classes' => self::CLASSES, 'series' => $series, 'totais' => $totais]);
    }

    public function exportar(Request $r): Response
    {
        $formato = $r->validate(['formato' => ['nullable', 'in:csv,xlsx']])['formato'] ?? 'csv';
        [$f, $series, $totais] = $this->dados($r);
        $cab = ['Período', 'Início', 'Fim'];
        foreach (self::CLASSES as $rot) {
            array_push($cab, "$rot entregues", "$rot devolvidas");
        }
        $linhas = array_map(fn ($s) => array_merge([$s['rotulo'], $s['inicio'], $s['fim']],
            ...array_map(fn ($k) => [$s[$k]['entregues'], $s[$k]['devolvidas']], array_keys(self::CLASSES))), $series);
        $linhas[] = array_merge(['Total', $f['de'], $f['ate']], ...array_map(fn ($k) => [$totais[$k]['entregues'], $totais[$k]['devolvidas']], array_keys(self::CLASSES)));
        $this->auditoria()->registrar('relatorio.exportado', 'SUCESSO', dados: ['relatorio' => 'entregas-devolucoes', 'formato' => $formato, 'filtros' => $f]);
        $nome = 'sigarb-entregas-devolucoes-'.now()->format('Ymd-His');
        $titulo = ImportacaoController::ROTULO.' · SIGARB · Entregas e devoluções ('.$f['agrupar'].') — '.$f['de'].' a '.$f['ate'];
        if ($formato === 'xlsx') {
            return response(Planilha::gerar($cab, $linhas, $titulo, 'Quantidades no escopo do seu perfil. Item individual conta 1; lote conta a quantidade.'), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"$nome.xlsx\"", 'Cache-Control' => 'no-store']);
        }

        return response()->streamDownload(function () use ($cab, $linhas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fwrite($out, '# '.ImportacaoController::ROTULO."\r\n");
            fputcsv($out, $cab, ';', '"', '');
            foreach ($linhas as $l) {
                fputcsv($out, array_map([RelatorioController::class, 'celula'], $l), ';', '"', '');
            }
            fclose($out);
        }, "$nome.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return array{0: array, 1: list<array>, 2: array} */
    private function dados(Request $r): array
    {
        $this->exigir('relatorios.entregas');
        $hoje = CarbonImmutable::now(self::FUSO)->toDateString();
        $f = $r->validate([
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
            'agrupar' => ['nullable', 'in:dia,semana,mes'], 'unidade_id' => ['nullable', 'integer'],
        ]);
        $f['agrupar'] ??= 'dia';
        $f['ate'] ??= $hoje;
        $f['de'] ??= CarbonImmutable::parse($f['ate'])->subDays(match ($f['agrupar']) {
            'dia' => 13, 'semana' => 7 * 8 - 1, 'mes' => 365
        })->toDateString();
        $de = CarbonImmutable::parse($f['de'], self::FUSO)->startOfDay();
        $ate = CarbonImmutable::parse($f['ate'], self::FUSO)->endOfDay();
        if ($de->diffInDays($ate) > self::MAX_DIAS) {
            throw ValidationException::withMessages(['de' => ['Período máximo de '.self::MAX_DIAS.' dias.']]);
        }

        $q = DB::table('movimentacoes as mv')->join('movimentacao_itens as mi', 'mi.movimentacao_id', '=', 'mv.id')
            ->join('locais_estoque as lo', 'lo.id', '=', 'mv.local_origem_id')
            ->leftJoin('itens_individuais as i', 'i.id', '=', 'mi.item_individual_id')->leftJoin('lotes as l', 'l.id', '=', 'mi.lote_id')
            ->join('materiais as m', 'm.id', '=', DB::raw('COALESCE(i.material_id, l.material_id)'))
            ->join('categorias as c', 'c.id', '=', 'm.categoria_id')->leftJoin('tipos_material as t', 't.id', '=', 'm.tipo_id')
            ->whereIn('mv.tipo', ['ENTREGA', 'DEVOLUCAO'])->whereNotNull('mv.militar_id')
            ->whereBetween('mv.ocorrido_em', [$de->setTimezone(config('app.timezone')), $ate->setTimezone(config('app.timezone'))]);
        $this->authz()->escopar($q, $r->user(), 'relatorios.entregas', 'lo.unidade_id');
        $this->authz()->ocultarDepositos($q, $r->user(), 'lo.id'); // depósito só para a gestão (2026-11-18)
        if (! empty($f['unidade_id'])) {
            $alvo = DB::table('unidades')->where('id', $f['unidade_id'])->value('caminho');
            $q->whereIn('lo.unidade_id', DB::table('unidades')->where('caminho', 'like', ($alvo ?? '-').'%')->select('id'));
        }
        $linhas = $q->get(['mv.tipo', 'mv.ocorrido_em', 'mi.quantidade', 't.perfil', 'c.natureza']);

        $vazio = fn () => array_map(fn () => ['entregues' => 0, 'devolvidas' => 0], self::CLASSES);
        $baldes = [];
        for ($d = $this->inicio($de, $f['agrupar']); $d <= $ate; $d = $this->proximo($d, $f['agrupar'])) {
            $baldes[$d->toDateString()] = ['inicio' => max($d, $de)->toDateString(), 'fim' => min($this->proximo($d, $f['agrupar'])->subDay(), $ate)->toDateString(),
                'rotulo' => $this->rotulo($d, $f['agrupar'])] + $vazio();
        }
        $totais = $vazio();
        foreach ($linhas as $l) {
            $k = $this->inicio(CarbonImmutable::parse($l->ocorrido_em, config('app.timezone'))->setTimezone(self::FUSO)->startOfDay(), $f['agrupar'])->toDateString();
            if (! isset($baldes[$k])) {
                continue;
            }
            $classe = $l->perfil === 'ARMA_FOGO' ? 'armas' : ($l->natureza === 'MUNICAO' ? 'municao' : 'demais');
            $campo = $l->tipo === 'ENTREGA' ? 'entregues' : 'devolvidas';
            $baldes[$k][$classe][$campo] += (int) $l->quantidade;
            $totais[$classe][$campo] += (int) $l->quantidade;
        }

        return [$f, array_values($baldes), $totais];
    }

    private function inicio(CarbonImmutable $d, string $agrupar): CarbonImmutable
    {
        return match ($agrupar) {
            'dia' => $d->startOfDay(), 'semana' => $d->startOfWeek(CarbonImmutable::MONDAY), 'mes' => $d->startOfMonth()
        };
    }

    private function proximo(CarbonImmutable $d, string $agrupar): CarbonImmutable
    {
        return match ($agrupar) {
            'dia' => $d->addDay(), 'semana' => $d->addWeek(), 'mes' => $d->addMonthNoOverflow()
        };
    }

    private function rotulo(CarbonImmutable $d, string $agrupar): string
    {
        $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return match ($agrupar) {
            'dia' => $d->format('d/m'),
            'semana' => 'Sem. '.$d->format('d/m'),
            'mes' => $meses[$d->month - 1].'/'.$d->year,
        };
    }
}
