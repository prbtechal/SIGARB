<?php

namespace App\Http\Controllers\Api;

use App\Services\ImportacaoService;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Support\Planilha;
use Illuminate\Support\Facades\DB;

/** CP-13 — importação/exportação de cadastros por planilha CSV (modelo, simulação, confirmação, exportação no escopo). */
class ImportacaoController extends ApiController
{
    /** entidade => [permissão para importar além de dados.importar (o endpoint de destino revalida), permissão para exportar] */
    private const PERMISSOES = [
        'categorias' => ['referencias.gerir', 'catalogo.ver'], 'fabricantes' => ['referencias.gerir', 'catalogo.ver'],
        'calibres' => ['referencias.gerir', 'catalogo.ver'], 'tipos-material' => ['referencias.gerir', 'catalogo.ver'],
        'niveis-protecao' => ['referencias.gerir', 'catalogo.ver'], 'materiais' => ['catalogo.gerir', 'catalogo.ver'],
        'unidades' => ['organizacao.gerir', 'organizacao.ver'], 'locais' => ['organizacao.gerir', 'organizacao.ver'],
        'efetivos' => ['militares.gerir', 'militares.ver'], 'estoque' => ['estoque.entrada', 'estoque.ver'],
    ];

    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $global = fn ($p) => $p === 'referencias.gerir' ? $this->authz()->global($u, $p) : $this->authz()->pode($u, $p);
        $importa = $this->authz()->pode($u, 'dados.importar');
        $exporta = $this->authz()->pode($u, 'relatorios.exportar');

        return response()->json(['data' => collect(ImportacaoService::ENTIDADES)->map(fn ($e, $k) => [
            'entidade' => $k, 'rotulo' => $e[0], 'colunas' => $e[1],
            'pode_importar' => $importa && $global(self::PERMISSOES[$k][0]),
            'pode_exportar' => $exporta && $this->authz()->pode($u, self::PERMISSOES[$k][1]),
        ])->filter(fn ($e) => $e['pode_importar'] || $e['pode_exportar'])->values(), 'max_linhas' => ImportacaoService::MAX_LINHAS]);
    }

    public function modelo(Request $r, string $entidade): Response
    {
        $e = $this->entidade($entidade);

        return $this->arquivo($r, "modelo-$entidade", $e[1], [$e[2]], "SIGARB · Modelo de importação — {$e[0]}",
            'Preencha uma linha por registro (apague a linha de exemplo). Colunas com * são obrigatórias. Não altere os cabeçalhos da tabela.');
    }

    public function importar(Request $r, string $entidade, ImportacaoService $svc): JsonResponse
    {
        $this->entidade($entidade);
        $this->exigir('dados.importar');
        $d = $r->validate(['arquivo' => ['required', 'file', 'max:2048', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream'],
            'simulacao' => ['required', 'boolean']]);

        return response()->json($svc->importar($r->user(), $entidade, $this->conteudo((string) file_get_contents($d['arquivo']->getRealPath()), $entidade), (bool) $d['simulacao']));
    }

    /**
     * .xlsx (assinatura ZIP) vira o mesmo texto CSV que o leitor já valida; CSV passa direto.
     * Pula a faixa de logos/título do modelo: os dados começam na linha cuja 1ª célula é a 1ª coluna do cadastro.
     */
    private function conteudo(string $bytes, string $entidade): string
    {
        if (! str_starts_with($bytes, "PK\x03\x04")) {
            return $bytes;
        }
        $linhas = Planilha::ler($bytes);
        $chave = fn ($v) => mb_strtolower(trim(rtrim(trim((string) $v), '*')));
        $primeira = $chave($this->entidade($entidade)[1][0]);
        foreach ($linhas as $i => $l) {
            if ($chave($l[0] ?? '') === $primeira) {
                $linhas = array_slice($linhas, $i);
                break;
            }
        }
        $mem = fopen('php://memory', 'w+');
        foreach ($linhas as $l) {
            fputcsv($mem, $l, ';', '"', '');
        }
        rewind($mem);

        return (string) stream_get_contents($mem);
    }

    public function exportar(Request $r, string $entidade): Response
    {
        $e = $this->entidade($entidade);
        $this->exigir('relatorios.exportar');
        $this->exigir(self::PERMISSOES[$entidade][1]);
        $u = $r->user();
        $linhas = match ($entidade) {
            'categorias' => DB::table('categorias')->orderBy('codigo')->get()->map(fn ($c) => [$c->codigo, $c->nome, $c->natureza]),
            'fabricantes', 'calibres' => DB::table($entidade)->orderBy('nome')->get()->map(fn ($c) => [$c->nome]),
            'niveis-protecao' => DB::table('niveis_protecao')->orderBy('nome')->get()->map(fn ($c) => [$c->nome, $c->descricao]),
            'tipos-material' => DB::table('tipos_material as t')->join('categorias as c', 'c.id', '=', 't.categoria_id')->orderBy('c.codigo')->orderBy('t.nome')
                ->get(['c.codigo', 't.nome', 't.perfil', 't.descricao'])->map(fn ($t) => [$t->codigo, $t->nome, $t->perfil, $t->descricao]),
            'materiais' => DB::table('materiais as m')->join('categorias as c', 'c.id', '=', 'm.categoria_id')->leftJoin('tipos_material as t', 't.id', '=', 'm.tipo_id')
                ->leftJoin('fabricantes as f', 'f.id', '=', 'm.fabricante_id')->leftJoin('calibres as k', 'k.id', '=', 'm.calibre_id')->orderBy('m.codigo')
                ->get(['m.codigo', 'm.nome', 'c.codigo as cat', 't.nome as tipo', 'm.modo_controle', 'f.nome as fab', 'm.modelo', 'k.nome as cal', 'm.marca', 'm.compatibilidade', 'm.cor', 'm.tamanho', 'm.unidade_medida'])
                ->map(fn ($m) => array_values((array) $m)),
            'unidades' => $this->escopo(DB::table('unidades as un')->join('organizacoes as o', 'o.id', '=', 'un.organizacao_id')->leftJoin('unidades as sup', 'sup.id', '=', 'un.unidade_superior_id')
                ->orderBy('un.caminho'), $u, 'un.id')->get(['o.sigla as org', 'un.sigla', 'un.nome', 'un.tipo', 'sup.sigla as sup'])->map(fn ($x) => [$x->org, $x->sigla, $x->nome, $x->tipo, $x->sup]),
            'locais' => $this->escopo(DB::table('locais_estoque as l')->join('unidades as un', 'un.id', '=', 'l.unidade_id')->orderBy('l.codigo'), $u, 'l.unidade_id')
                ->get(['un.sigla', 'l.codigo', 'l.nome', 'l.tipo'])->map(fn ($x) => [$x->sigla, $x->codigo, $x->nome, $x->tipo]),
            'efetivos' => $this->efetivos($u),
            'estoque' => $this->estoque($u),
        };
        $this->auditoria()->registrar('dados.exportados', 'SUCESSO', dados: ['entidade' => $entidade, 'linhas' => count($linhas)]);

        return $this->arquivo($r, "sigarb-$entidade-".now()->format('Ymd-His'), array_map(fn ($c) => rtrim($c, '*'), $e[1]), $linhas,
            self::ROTULO." · SIGARB · {$e[0]} — exportado em ".now()->format('d/m/Y H:i'), 'Só sai o que o seu perfil pode ver. Esta exportação ficou registrada na auditoria.', true);
    }

    private function escopo($q, $u, string $coluna)
    {
        return $this->authz()->escopar($q, $u, 'organizacao.ver', $coluna);
    }

    /** P-09: sem militares.ver_detalhe na unidade, matrícula mascarada e contatos vazios (mesmo corte da tela). */
    private function efetivos($u): iterable
    {
        $q = DB::table('militares as m')->join('postos_graduacoes as p', 'p.id', '=', 'm.posto_graduacao_id')->join('unidades as un', 'un.id', '=', 'm.unidade_id')
            ->orderBy('un.sigla')->orderBy('m.nome');
        $this->authz()->escopar($q, $u, 'militares.ver', 'm.unidade_id');
        $detalhe = $this->authz()->unidadesPermitidas($u, 'militares.ver_detalhe');

        return $q->get(['m.*', 'p.sigla as posto', 'un.sigla as un'])->map(function ($m) use ($detalhe) {
            $ve = $detalhe === null || in_array($m->unidade_id, $detalhe);

            return [$ve ? $m->matricula : '•••'.substr($m->matricula, -3), $m->nome, $m->nome_guerra, $m->posto, $m->un, $m->situacao, $m->quadro, $m->funcao,
                $ve ? $m->telefone_institucional : '', $ve ? $m->email_institucional : ''];
        });
    }

    /** Fotografia do estoque no escopo, nas colunas do modelo de entrada (documento/origem vazios). */
    private function estoque($u): iterable
    {
        $ind = DB::table('itens_individuais as i')->join('locais_estoque as l', 'l.id', '=', 'i.local_estoque_id')->join('materiais as m', 'm.id', '=', 'i.material_id')
            ->whereNotIn('i.situacao', ['BAIXADO', 'ESTORNADO', 'FORA_DO_ESTOQUE'])->orderBy('l.codigo')->orderBy('i.identificador');
        $this->authz()->escopar($ind, $u, 'estoque.ver', 'l.unidade_id');
        $lot = DB::table('saldos_estoque as s')->join('locais_estoque as l', 'l.id', '=', 's.local_estoque_id')->join('lotes as lo', 'lo.id', '=', 's.lote_id')
            ->join('materiais as m', 'm.id', '=', 'lo.material_id')->leftJoin('fabricantes as f', 'f.id', '=', 'lo.fabricante_id')->where('s.quantidade', '>', 0)
            ->orderBy('l.codigo')->orderBy('lo.codigo');
        $this->authz()->escopar($lot, $u, 'estoque.ver', 'l.unidade_id');

        return $ind->get(['l.codigo as local', 'm.codigo as mat', 'i.identificador', 'i.numero_serie', 'i.estado_conservacao', 'i.tamanho', 'i.nivel_protecao', 'i.fabricado_em', 'i.validade'])
            ->map(fn ($i) => [$i->local, '', '', $i->mat, $i->identificador, $i->numero_serie, $i->estado_conservacao, $i->tamanho, $i->nivel_protecao, $i->fabricado_em, $i->validade, '', '', ''])
            ->concat($lot->get(['l.codigo as local', 'm.codigo as mat', 'lo.codigo as lote', 's.quantidade', 'lo.validade', 'f.nome as fab'])
                ->map(fn ($s) => [$s->local, '', '', $s->mat, '', '', '', '', '', '', $s->validade, $s->lote, $s->quantidade, $s->fab]));
    }

    private function entidade(string $entidade): array
    {
        return ImportacaoService::ENTIDADES[$entidade] ?? throw new SigarbException('ENTIDADE_INEXISTENTE', 'Cadastro sem importação/exportação.', 404);
    }

    /** ?formato=xlsx gera planilha do Excel; padrão continua CSV. */
    /** PSI-PRB 7.5 / Anexo III: exportação com dados de inventário/efetivo leva rótulo visível. O modelo (vazio) não. */
    public const ROTULO = 'INFORMAÇÃO RESTRITA – PSI-PRB';

    private function arquivo(Request $r, string $nome, array $cab, iterable $linhas, string $titulo, string $instrucao, bool $restrito = false): Response
    {
        if ($r->query('formato') === 'xlsx') {
            return response(Planilha::gerar($cab, $linhas, $titulo, $instrucao), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"$nome.xlsx\"", 'Cache-Control' => 'no-store']);
        }
        $nome .= '.csv';

        return response()->streamDownload(function () use ($cab, $linhas, $restrito) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos corretos no Excel
            if ($restrito) {
                fwrite($out, '# '.self::ROTULO."\r\n"); // linha de rótulo; a importação ignora linhas iniciais com "#"
            }
            fputcsv($out, $cab, ';', '"', '');
            foreach ($linhas as $l) {
                fputcsv($out, array_map([RelatorioController::class, 'celula'], (array) $l), ';', '"', '');
            }
            fclose($out);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
