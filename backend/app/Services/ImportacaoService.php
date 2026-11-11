<?php

namespace App\Services;

use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\EstoqueController;
use App\Http\Controllers\Api\MilitarController;
use App\Http\Controllers\Api\OrganizacaoController;
use App\Http\Controllers\Api\ReferenciaController;
use App\Models\Usuario;
use App\Support\SigarbException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * CP-13 — importação de planilhas (CSV UTF-8, ";" ou ","). Cada linha vira o MESMO payload que a tela enviaria e passa pelo
 * MESMO controller (validação, autorização por escopo, regras de perfil, auditoria). O arquivo inteiro roda numa transação:
 * simulação ou qualquer erro ⇒ nada fica gravado; confirmação sem erro ⇒ tudo gravado. Códigos/nomes viram ids aqui.
 */
class ImportacaoService
{
    public const MAX_LINHAS = 2000;

    /** entidade => [rótulo, colunas (obrigatórias com *), exemplo]. A ordem das colunas é a do modelo. */
    public const ENTIDADES = [
        'categorias' => ['Categorias de material', ['codigo*', 'nome*', 'natureza*'], ['EPI', 'Equipamento de proteção', 'EQUIPAMENTO']],
        'fabricantes' => ['Fabricantes', ['nome*'], ['Fabricante Exemplo Ltda']],
        'calibres' => ['Calibres', ['nome*'], ['9 mm']],
        'tipos-material' => ['Tipos de material', ['categoria_codigo*', 'nome*', 'perfil*', 'descricao'], ['EPI', 'Colete balístico', 'COLETE', 'Colete de uso individual']],
        'niveis-protecao' => ['Níveis de proteção', ['nome*', 'descricao'], ['IIIA', 'Nível de proteção balística IIIA']],
        // CP-14 (P-02): estrutura real da instituição. Linhas na ordem do arquivo: a unidade superior pode vir numa linha anterior.
        'unidades' => ['Unidades e subunidades', ['organizacao_sigla*', 'sigla*', 'nome*', 'tipo*', 'unidade_superior_sigla'], ['ORG', '1CIA', '1ª Companhia', 'UNIDADE', '']],
        'locais' => ['Locais de estoque', ['unidade_sigla*', 'codigo*', 'nome*', 'tipo*'], ['1CIA', 'RES-1CIA', 'Reserva de armamento da 1ª Cia', 'RESERVA']],
        'materiais' => ['Materiais (catálogo)', ['codigo*', 'nome*', 'categoria_codigo*', 'tipo', 'modo_controle*', 'fabricante', 'modelo', 'calibre', 'marca', 'compatibilidade', 'cor', 'tamanho', 'unidade_medida'],
            ['COL-IIIA-M', 'Colete balístico IIIA', 'EPI', 'Colete balístico', 'INDIVIDUAL', 'Fabricante Exemplo Ltda', 'Modelo X', '', 'Marca X', '', 'Preto', 'M', 'un']],
        'efetivos' => ['Efetivos', ['matricula*', 'nome*', 'nome_guerra*', 'posto_sigla*', 'unidade_sigla*', 'situacao', 'quadro', 'funcao', 'telefone_institucional', 'email_institucional'],
            ['EX-0001', 'Pessoa Exemplo da Silva', 'EXEMPLO', 'SD', '1CIA', 'ATIVO', 'Quadro exemplo', 'Patrulheiro', '(00) 0000-0000', 'exemplo@instituicao.invalid']],
        'estoque' => ['Entrada de estoque (itens e lotes)', ['local_codigo*', 'documento_referencia*', 'origem*', 'material_codigo*', 'identificador', 'numero_serie', 'estado_conservacao', 'tamanho', 'nivel_protecao', 'fabricado_em', 'validade', 'lote_codigo', 'quantidade', 'fabricante'],
            ['RES-1CIA', 'NF-0001/2026', 'Carga inicial', 'COL-IIIA-M', 'COL-0001', 'SN-0001', 'NOVO', 'M', 'IIIA', '2025-01-10', '2030-01-10', '', '', '']],
    ];

    /** Usuário da importação em curso (para filtrar buscas pelo escopo). */
    private ?Usuario $usuario = null;

    public function __construct(private AuditoriaService $auditoria, private AutorizacaoService $authz) {}

    /** @return array{0: list<string>, 1: list<array<string, string>>} */
    public function ler(string $conteudo): array
    {
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            throw new SigarbException('ARQUIVO_INVALIDO', 'O arquivo não está em UTF-8. No Excel, use "Salvar como" → "CSV UTF-8".', 422);
        }
        $linhas = preg_split('/\r\n|\n|\r/', trim($conteudo));
        while ($linhas && str_starts_with(ltrim($linhas[0], '"'), '#')) {
            array_shift($linhas); // rótulo de classificação (PSI-PRB 7.5) de arquivo exportado pelo próprio SIGARB
        }
        $sep = substr_count($linhas[0] ?? '', ';') >= substr_count($linhas[0] ?? '', ',') ? ';' : ',';
        $cab = array_map(fn ($c) => Str::lower(trim(rtrim(trim($c), '*'))), str_getcsv(array_shift($linhas) ?? '', $sep, '"', ''));
        $dados = [];
        foreach ($linhas as $i => $l) {
            if (trim(str_replace($sep, '', $l)) === '') {
                continue; // linha em branco
            }
            $v = str_getcsv($l, $sep, '"', '');
            $linha = [];
            foreach ($cab as $j => $c) {
                // Desfaz a neutralização de fórmula da exportação ('=..., '+..., '-..., '@...).
                $linha[$c] = preg_replace("/^'(?=[=+\\-@])/", '', trim($v[$j] ?? ''));
            }
            $linha['__linha'] = (string) ($i + 2);
            $dados[] = $linha;
        }

        return [$cab, $dados];
    }

    /** @return array{entidade: string, simulacao: bool, total: int, gravadas: int, erros: list<array{linha: int, campo: ?string, mensagem: string}>} */
    public function importar(Usuario $u, string $entidade, string $conteudo, bool $simulacao): array
    {
        [$cab, $linhas] = $this->ler($conteudo);
        $previstas = array_map(fn ($c) => rtrim($c, '*'), self::ENTIDADES[$entidade][1]);
        // P-09: coluna fora do modelo (ex.: CPF) é recusada — dado não previsto não entra.
        if ($sobra = array_values(array_diff($cab, $previstas))) {
            throw new SigarbException('COLUNA_DESCONHECIDA', 'Colunas fora do modelo: '.implode(', ', $sobra).'. Baixe o modelo e mantenha só as colunas dele.', 422);
        }
        $faltam = array_values(array_diff(array_map(fn ($c) => rtrim($c, '*'), array_filter(self::ENTIDADES[$entidade][1], fn ($c) => str_ends_with($c, '*'))), $cab));
        if ($faltam) {
            throw new SigarbException('COLUNA_AUSENTE', 'Colunas obrigatórias ausentes: '.implode(', ', $faltam).'.', 422);
        }
        if (! $linhas) {
            throw new SigarbException('ARQUIVO_VAZIO', 'A planilha não tem linhas de dados.', 422);
        }
        if (count($linhas) > self::MAX_LINHAS) {
            throw new SigarbException('ARQUIVO_GRANDE', 'Máximo de '.self::MAX_LINHAS.' linhas por arquivo; divida a planilha.', 422);
        }

        $this->usuario = $u;
        $erros = [];
        $gravadas = 0;
        DB::beginTransaction();
        try {
            if ($entidade === 'estoque') {
                $gravadas = $this->estoque($u, $linhas, $erros);
            } else {
                foreach ($linhas as $l) {
                    $gravadas += $this->linha($u, $entidade, $l, $erros) ? 1 : 0;
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack(); // erro inesperado: nunca grava parcial

            throw $e;
        }
        if ($simulacao || $erros) {
            DB::rollBack();
        } else {
            DB::commit();
        }
        // Negações de acesso ficam na trilha mesmo com o rollback (registradas fora da transação da importação).
        $negadas = array_values(array_unique(array_column(array_filter($erros, fn ($e) => $e['negado'] ?? false), 'linha')));
        if ($negadas) {
            $this->auditoria->registrar('acesso.negado', 'NEGADO', dados: ['origem' => 'importacao', 'entidade' => $entidade, 'linhas' => $negadas]);
        }
        $erros = array_map(fn ($e) => array_diff_key($e, ['negado' => 1]), $erros);
        $ok = ! $simulacao && ! $erros;
        $this->auditoria->registrar('importacao.'.($simulacao ? 'simulada' : 'executada'), $ok || $simulacao ? 'SUCESSO' : 'FALHA', dados: [
            'entidade' => $entidade, 'linhas' => count($linhas), 'gravadas' => $ok ? $gravadas : 0, 'erros' => count($erros)]);

        return ['entidade' => $entidade, 'simulacao' => $simulacao, 'total' => count($linhas), 'gravadas' => $ok ? $gravadas : 0, 'validas' => $gravadas, 'erros' => $erros];
    }

    private function linha(Usuario $u, string $entidade, array $l, array &$erros): bool
    {
        $n = (int) $l['__linha'];
        $v = fn (string $c) => ($l[$c] ?? '') === '' ? null : $l[$c];
        try {
            [$acao, $dados] = match ($entidade) {
                'categorias' => [fn () => app()->call([app(ReferenciaController::class), 'store'], ['tipo' => 'categorias']),
                    ['codigo' => $v('codigo'), 'nome' => $v('nome'), 'natureza' => $v('natureza') ? Str::upper($v('natureza')) : null]],
                'fabricantes', 'calibres' => [fn () => app()->call([app(ReferenciaController::class), 'store'], ['tipo' => $entidade]), ['nome' => $v('nome')]],
                'niveis-protecao' => [fn () => app()->call([app(ReferenciaController::class), 'store'], ['tipo' => 'niveis-protecao']),
                    ['nome' => $v('nome'), 'descricao' => $v('descricao')]],
                'tipos-material' => [fn () => app()->call([app(ReferenciaController::class), 'store'], ['tipo' => 'tipos-material']),
                    ['categoria_id' => $this->id('categorias', 'codigo', $v('categoria_codigo'), 'categoria_codigo'), 'nome' => $v('nome'),
                        'perfil' => $v('perfil') ? Str::upper($v('perfil')) : null, 'descricao' => $v('descricao')]],
                'unidades' => [fn () => app()->call([app(OrganizacaoController::class), 'criarUnidade']), $this->unidade($v)],
                'locais' => [fn () => app()->call([app(OrganizacaoController::class), 'criarLocal']), array_filter([
                    'unidade_id' => $this->id('unidades', 'sigla', $v('unidade_sigla'), 'unidade_sigla', ['organizacao.gerir', 'id']),
                    'codigo' => $v('codigo') ? Str::upper($v('codigo')) : null, 'nome' => $v('nome'), 'tipo' => $v('tipo') ? Str::upper($v('tipo')) : null], fn ($x) => $x !== null)],
                'materiais' => [fn () => app()->call([app(CatalogoController::class), 'criarMaterial']), $this->material($v)],
                'efetivos' => [fn () => app()->call([app(MilitarController::class), 'store']), array_filter([
                    'matricula' => $v('matricula') ? Str::upper($v('matricula')) : null, 'nome' => $v('nome'), 'nome_guerra' => $v('nome_guerra'),
                    'posto_graduacao_id' => $this->id('postos_graduacoes', 'sigla', $v('posto_sigla'), 'posto_sigla'),
                    'unidade_id' => $this->id('unidades', 'sigla', $v('unidade_sigla'), 'unidade_sigla', ['militares.gerir', 'id']),
                    'situacao' => $v('situacao') ? Str::upper($v('situacao')) : null, 'quadro' => $v('quadro'), 'funcao' => $v('funcao'),
                    'telefone_institucional' => $v('telefone_institucional'), 'email_institucional' => $v('email_institucional')], fn ($x) => $x !== null)],
            };
            $this->chamar($u, $dados, $acao);

            return true;
        } catch (\Throwable $e) {
            foreach ($this->mensagens($e) as [$campo, $msg]) {
                $erros[] = ['linha' => $n, 'campo' => $campo, 'mensagem' => $msg, 'negado' => $this->negado($e)];
            }

            return false;
        }
    }

    /** Agrupa linhas por local + documento + origem em entradas de até max_itens itens; erros voltam para a linha de origem. */
    private function estoque(Usuario $u, array $linhas, array &$erros): int
    {
        $grupos = [];
        foreach ($linhas as $l) {
            $grupos[($l['local_codigo'] ?? '')."\0".($l['documento_referencia'] ?? '')."\0".($l['origem'] ?? '')][] = $l;
        }
        $gravadas = 0;
        foreach ($grupos as $grupo) {
            foreach (array_chunk($grupo, config('sigarb.movimentacao.max_itens')) as $bloco) {
                $itens = [];
                $falhou = false;
                foreach ($bloco as $l) {
                    $v = fn (string $c) => ($l[$c] ?? '') === '' ? null : $l[$c];
                    try {
                        $itens[] = array_filter([
                            'material_id' => $this->id('materiais', 'codigo', $v('material_codigo'), 'material_codigo'),
                            'identificador' => $v('identificador'), 'numero_serie' => $v('numero_serie'),
                            'estado_conservacao' => $v('estado_conservacao') ? Str::upper($v('estado_conservacao')) : null,
                            'tamanho' => $v('tamanho'), 'nivel_protecao' => $v('nivel_protecao'), 'fabricado_em' => $v('fabricado_em'), 'validade' => $v('validade'),
                            'lote_codigo' => $v('lote_codigo'), 'quantidade' => $v('quantidade'),
                            'fabricante_id' => $v('fabricante') ? $this->id('fabricantes', 'nome', $v('fabricante'), 'fabricante') : null,
                        ], fn ($x) => $x !== null);
                    } catch (\Throwable $e) {
                        $falhou = true;
                        foreach ($this->mensagens($e) as [$campo, $msg]) {
                            $erros[] = ['linha' => (int) $l['__linha'], 'campo' => $campo, 'mensagem' => $msg, 'negado' => $this->negado($e)];
                        }
                    }
                }
                if ($falhou) {
                    continue;
                }
                $p = $bloco[0];
                try {
                    $dados = ['tipo' => 'ENTRADA', 'local_destino_id' => $this->id('locais_estoque', 'codigo', $p['local_codigo'] ?: null, 'local_codigo', ['estoque.entrada', 'unidade_id']),
                        'documento_referencia' => $p['documento_referencia'] ?: null, 'origem_descricao' => $p['origem'] ?: null, 'itens' => $itens];
                    $this->chamar($u, $dados, fn () => app()->call([app(EstoqueController::class), 'registrar']), ['Idempotency-Key' => 'importacao-'.Str::ulid()]);
                    $gravadas += count($bloco);
                } catch (\Throwable $e) {
                    foreach ($this->mensagens($e) as [$campo, $msg]) {
                        // "itens.3.validade" → linha do 4º item do bloco
                        $linha = preg_match('/^itens\.(\d+)\.(.+)$/', (string) $campo, $m) ? (int) $bloco[(int) $m[1]]['__linha'] : (int) $p['__linha'];
                        $erros[] = ['linha' => $linha, 'campo' => isset($m[2]) ? $m[2] : $campo, 'mensagem' => $msg, 'negado' => $this->negado($e)];
                    }
                }
            }
        }

        return $gravadas;
    }

    private function unidade(callable $v): array
    {
        $org = $this->id('organizacoes', 'sigla', $v('organizacao_sigla'), 'organizacao_sigla');
        $sup = null;
        if ($v('unidade_superior_sigla')) {
            $q = DB::table('unidades')->where('organizacao_id', $org)->where('sigla', $v('unidade_superior_sigla'));
            $this->authz->escopar($q, $this->usuario, 'organizacao.gerir', 'id');
            $sup = $q->value('id') ?? throw new SigarbException('NAO_ENCONTRADO', "\"{$v('unidade_superior_sigla')}\" não encontrado.", 422, ['campo' => 'unidade_superior_sigla']);
        }

        return array_filter(['organizacao_id' => $org, 'unidade_superior_id' => $sup, 'sigla' => $v('sigla') ? Str::upper($v('sigla')) : null,
            'nome' => $v('nome'), 'tipo' => $v('tipo') ? Str::upper($v('tipo')) : null], fn ($x) => $x !== null);
    }

    private function material(callable $v): array
    {
        $cat = $this->id('categorias', 'codigo', $v('categoria_codigo'), 'categoria_codigo');

        return array_filter([
            'codigo' => $v('codigo') ? Str::upper($v('codigo')) : null, 'nome' => $v('nome'), 'categoria_id' => $cat,
            'tipo_id' => $v('tipo') ? (DB::table('tipos_material')->where('categoria_id', $cat)->where('nome', $v('tipo'))->value('id')
                ?? throw new SigarbException('NAO_ENCONTRADO', "Tipo \"{$v('tipo')}\" não existe nessa categoria.", 422, ['campo' => 'tipo'])) : null,
            'modo_controle' => $v('modo_controle') ? Str::upper($v('modo_controle')) : null,
            'fabricante_id' => $v('fabricante') ? $this->id('fabricantes', 'nome', $v('fabricante'), 'fabricante') : null,
            'calibre_id' => $v('calibre') ? $this->id('calibres', 'nome', $v('calibre'), 'calibre') : null,
            'modelo' => $v('modelo'), 'marca' => $v('marca'), 'compatibilidade' => $v('compatibilidade'), 'cor' => $v('cor'), 'tamanho' => $v('tamanho'),
            'unidade_medida' => $v('unidade_medida'),
        ], fn ($x) => $x !== null);
    }

    /**
     * Código/nome → id; ausente ou ambíguo vira erro da coluna. Com $escopo [permissão, coluna da unidade], só enxerga linhas
     * das unidades permitidas: fora do escopo responde igual a inexistente (sem oráculo de existência).
     */
    private function id(string $tabela, string $coluna, ?string $valor, string $campo, ?array $escopo = null): ?int
    {
        if ($valor === null) {
            return null; // obrigatoriedade fica com a validação do endpoint
        }
        $q = DB::table($tabela)->where($coluna, $valor);
        if ($escopo) {
            $this->authz->escopar($q, $this->usuario, $escopo[0], $escopo[1]);
        }
        $ids = $q->limit(2)->pluck('id');
        if ($ids->count() !== 1) {
            throw new SigarbException('NAO_ENCONTRADO', $ids->isEmpty() ? "\"$valor\" não encontrado." : "\"$valor\" é ambíguo.", 422, ['campo' => $campo]);
        }

        return (int) $ids[0];
    }

    /** Executa o controller com uma requisição interna do mesmo usuário; restaura a requisição original sempre. */
    private function chamar(Usuario $u, array $dados, callable $acao, array $cabecalhos = []): void
    {
        $sub = Request::create('/api/v1/importacao', 'POST', $dados, server: ['HTTP_ACCEPT' => 'application/json']);
        foreach ($cabecalhos as $k => $c) {
            $sub->headers->set($k, $c);
        }
        $sub->setUserResolver(fn () => $u);
        $original = app('request');
        app()->instance('request', $sub);
        Facade::clearResolvedInstance('request');
        try {
            $acao();
        } finally {
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }

    private function negado(\Throwable $e): bool
    {
        return $e instanceof AuthorizationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403);
    }

    /** @return list<array{0: ?string, 1: string}> */
    private function mensagens(\Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => collect($e->errors())->flatMap(fn ($ms, $c) => array_map(fn ($m) => [$c, $m], $ms))->values()->all(),
            $e instanceof SigarbException => [[$e->detalhes['campo'] ?? null, $e->getMessage()]],
            $e instanceof AuthorizationException => [[null, 'Sem permissão para cadastrar este registro no seu escopo.']],
            $e instanceof HttpExceptionInterface => [[null, $e->getStatusCode() === 403 ? 'Sem permissão para cadastrar este registro no seu escopo.' : ($e->getMessage() ?: 'Registro inválido.')]],
            default => throw $e,
        };
    }
}
