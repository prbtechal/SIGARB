<?php

namespace App\Services;

use App\Models\Calibre;
use App\Models\Categoria;
use App\Models\Fabricante;
use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Movimentacao;
use App\Models\Organizacao;
use App\Models\TipoMaterial;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\CatalogoOficial;
use App\Support\PerfilMaterial;
use App\Support\Planilha;
use App\Support\SigarbException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Carga inicial do inventário de material bélico (planilha "Inventario_Material_Belico_2026", abas por território).
 *
 * - Catálogo, referências, unidades e locais que faltam são criados (firstOrCreate) com a mesma auditoria da tela.
 * - Estoque entra SÓ por EstoqueService::registrarEntrada (operação ENTRADA, sujeita ao portão de operações):
 *   uma movimentação por linha do documento, com chave de idempotência natural — rodar de novo não duplica (replay).
 * - Tudo numa transação: simulação ou qualquer erro de linha ⇒ nada fica gravado (mesma regra da importação CSV).
 */
class CargaInventarioService
{
    public const DOCUMENTO = 'Inventário Material Bélico 2026 – Portaria 01/2023/SPRB';

    public const LOTE = 'INV2026';

    /**
     * Grupo do documento (rótulo do quadro "Resumo" da aba ou título de bloco, normalizado) => [local, nome do local, tipo, unidade].
     * Todas as "Seções no documento" de um território (RESERVA X, BASE X, RÁDIO HT – X…) são o mesmo lugar e caem no mesmo local.
     */
    public const LOCAIS = [
        'POCO' => ['RES-POCO', 'Reserva Poço', 'RESERVA', 'MACEIO-POCO'],
        'ARAPIRACA' => ['RES-ARAPIRACA', 'Reserva Arapiraca', 'RESERVA', 'ARAPIRACA'],
        'BENEDITO BENTES' => ['RES-BENEDITO', 'Reserva Benedito Bentes', 'RESERVA', 'MACEIO-BENEDITO'],
        'MARECHAL DEODORO - FRANCES' => ['RES-FRANCES', 'Base Marechal Deodoro - Francês', 'RESERVA', 'MARECHAL-DEODORO-FRANCES'],
        'RIO LARGO' => ['RES-RIO-LARGO', 'Base Rio Largo', 'RESERVA', 'RIO-LARGO'],
        'PARIPUEIRA' => ['RES-PARIPUEIRA', 'Base Paripueira', 'RESERVA', 'PARIPUEIRA'],
        'ESTOQUE SMB' => ['DEP-SMB', 'Estoque SMB - Seção de Materiais Bélicos', 'DEPOSITO', 'POCO-SMB'],
        'LOCAL NAO INFORMADO' => ['DEP-SMB', 'Estoque SMB - Seção de Materiais Bélicos', 'DEPOSITO', 'POCO-SMB'], // marcado na justificativa
        'LOGISTICA' => ['RES-LOGISTICA', 'Seção de Logística', 'RESERVA', 'POCO-LOGISTICA'],
        'SOCIAL' => ['RES-SOCIAL', 'Seção Social', 'RESERVA', 'POCO-SOCIAL'],
        'GUARDA A BASE' => ['RES-GUARDA-BASE', 'Guarda a Base', 'RESERVA', 'GUARDA-BASE'],
        'COPOM' => ['RES-COPOM', 'COPOM', 'RESERVA', 'COPOM'],
    ];

    /** Unidades que não existiam na estrutura: sigla => [nome, sigla da superior]. Sob o Poço por decisão do responsável (2026-11-11). */
    public const UNIDADES_NOVAS = [
        'COPOM' => ['COPOM', 'MACEIO-POCO'],
        'GUARDA-BASE' => ['Guarda a Base', 'MACEIO-POCO'],
    ];

    /** Grupo do documento => prefixo da chave de idempotência (era o do identificador antes da geração automática). Destino: CatalogoOficial::CARGA. */
    public const PREFIXOS = ['ARMAMENTO' => 'ARM', 'TASER' => 'TSR', 'MUNICAO' => '', 'COLETE' => 'COL', 'ALGEMA' => 'ALG', 'COMUNICACAO' => 'HT'];

    /** Seção 3.N do documento => categoria. */
    private const SECOES = [1 => 'ARMAMENTO', 2 => 'TASER', 3 => 'MUNICAO', 4 => 'COLETE', 5 => 'ALGEMA', 6 => 'COMUNICACAO'];

    /** Coluna do quadro "Resumo" (normalizada, por prefixo) => chave de contagem. */
    private const RESUMO = ['PISTOLAS' => 'PISTOLAS', 'TASERS' => 'TASERS', 'MUNICOES' => 'MUNICOES', 'COLETES P' => 'COLETES P', 'COLETES M' => 'COLETES M',
        'COLETES G' => 'COLETES G', 'ALGEMAS' => 'ALGEMAS', 'RADIOS' => 'RADIOS', 'ACESSORIOS TASER' => 'ACESSORIOS TASER'];

    /** Categoria da aba Conferência (normalizada) => chave de contagem. */
    private const CONFERENCIA = ['ARMAMENTO' => 'ARMAMENTO', 'TASER' => 'TASER', 'ACESSORIOS TASER' => 'ACESSORIO', 'MUNICAO' => 'MUNICAO',
        'COLETE BALISTICO' => 'COLETE', 'ALGEMA' => 'ALGEMA', 'RADIO HT' => 'COMUNICACAO'];

    private array $saida = [];

    public function __construct(private EstoqueService $estoque, private AuditoriaService $auditoria, private AutorizacaoService $authz) {}

    public static function norm(?string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::upper(Str::ascii((string) $s))));
    }

    /**
     * Lê as abas de território. Cada registro = uma linha de item do documento.
     *
     * @return array{registros: list<array>, resumos: array, subtotais: list<array>, conferencia: list<array>, ignoradas: list<string>, notas: list<string>}
     */
    public function ler(string $bytes): array
    {
        $abas = Planilha::abas($bytes);
        $out = ['registros' => [], 'resumos' => [], 'subtotais' => [], 'conferencia' => [], 'ignoradas' => [], 'notas' => []];
        foreach ($abas as $nome => $linhas) {
            $n = self::norm($nome);
            if ($n === 'CONFERENCIA') {
                $out['conferencia'] = $this->lerConferencia($linhas);

                continue;
            }
            if ($n === 'RESUMO') {
                foreach ($linhas as $l) {
                    if (str_starts_with(trim($l[0] ?? ''), '•')) {
                        $out['notas'][] = trim($l[0]); // pontos de atenção do documento
                    }
                }

                continue;
            }
            if (in_array($n, ['CONSOLIDADO', 'INFO DO DOCUMENTO'], true)) {
                continue; // derivadas das abas de território / metadados com nomes de pessoas (não lidos)
            }
            $this->lerAba($nome, $linhas, $out);
        }

        return $out;
    }

    private function lerAba(string $aba, array $linhas, array &$out): void
    {
        $grupo = $cat = $secao = $cab = $resumoCab = null;
        $acessorio = false;
        $bloco = null; // índice em $out['subtotais'] da tabela aberta
        foreach ($linhas as $i => $l) {
            $nl = $i + 1;
            $a = trim((string) ($l[0] ?? ''));
            $preench = array_values(array_filter($l, fn ($v) => trim((string) $v) !== ''));
            if (! $preench) {
                $resumoCab = null;

                continue;
            }
            if ($a === 'Resumo') {
                $resumoCab = array_map(fn ($h) => $this->chaveResumo(self::norm($h)), $l);

                continue;
            }
            if ($resumoCab !== null) { // linha do quadro Resumo: rótulo + totais declarados
                $g = self::norm($a);
                $grupo ??= $g; // primeiro rótulo = grupo padrão da aba
                foreach ($resumoCab as $j => $k) {
                    if ($k !== null && $j > 0) {
                        $out['resumos'][$g][$k] = (int) ($l[$j] ?? 0);
                    }
                }

                continue;
            }
            if (count($preench) === 1 && isset(self::LOCAIS[self::norm($a)])) {
                $grupo = self::norm($a); // título de bloco (ESTOQUE SMB, LOCAL NÃO INFORMADO, LOGÍSTICA…)

                continue;
            }
            if (preg_match('/^3\.(\d)\b/', $a, $m)) {
                $cat = self::SECOES[(int) $m[1]] ?? null;
                $acessorio = $cat === 'TASER' && str_contains(self::norm($a), 'ACESSORIO');
                $secao = $cab = null;
                if ($cat === null) {
                    $out['ignoradas'][] = "$aba!$nl: seção \"$a\" não reconhecida (linhas seguintes ignoradas até a próxima seção).";
                }

                continue;
            }
            if (str_starts_with($a, 'Seção no documento:')) {
                $secao = trim(Str::after($a, ':'));
                $cab = null;

                continue;
            }
            if ($a === '#') {
                $cab = [];
                foreach ($l as $j => $h) {
                    $h = self::norm($h);
                    $campo = match (true) {
                        str_contains($h, 'SERIE') || $h === 'NUMERACAO' || $h === 'SERIAL' => 'serie',
                        $h === 'DESCRICAO' => 'descricao', $h === 'ORIGEM' => 'origem', str_starts_with($h, 'OBSERVA') => 'obs',
                        $h === 'SITUACAO' => 'situacao', $h === 'QTD' => 'qtd', $h === 'TAMANHO' => 'tamanho', str_starts_with($h, 'VENC') => 'venc',
                        $h === 'TEI' => 'tei', $h === 'ISSI' => 'issi', str_starts_with($h, 'PATRIMONIO') => 'patrimonio', default => null,
                    };
                    if ($campo) {
                        $cab[$campo] = $j;
                    }
                }
                $out['subtotais'][] = ['aba' => $aba, 'linha' => $nl, 'secao' => $secao, 'soma' => 0, 'declarado' => null];
                $bloco = array_key_last($out['subtotais']);

                continue;
            }
            $sub = array_search('SUBTOTAL', array_map(fn ($v) => self::norm($v), $l), true);
            if ($sub !== false) {
                if ($bloco !== null) {
                    $out['subtotais'][$bloco]['declarado'] = (int) ($l[$sub + 1] ?? 0);
                }
                $bloco = null;

                continue;
            }
            if (ctype_digit($a) && $cab !== null && $cat !== null) {
                $v = fn (string $c) => isset($cab[$c]) ? trim((string) ($l[$cab[$c]] ?? '')) : '';
                $r = ['aba' => $aba, 'linha' => $nl, 'num' => (int) $a, 'grupo' => $grupo, 'secao' => $secao, 'cat' => $cat, 'acessorio' => $acessorio || ($cat === 'TASER' && ! isset($cab['serie'])),
                    'descricao' => $v('descricao'), 'serie' => $v('serie'), 'origem' => $v('origem'), 'obs' => $v('obs'), 'situacao' => $v('situacao'),
                    'qtd' => $v('qtd'), 'tamanho' => $v('tamanho'), 'venc' => $v('venc'), 'tei' => $v('tei'), 'issi' => $v('issi'), 'patrimonio' => $v('patrimonio')];
                $out['registros'][] = $r;
                if ($bloco !== null) {
                    $out['subtotais'][$bloco]['soma'] += (int) $r['qtd'];
                }

                continue;
            }
            if ($grupo !== null || $cat !== null) {
                $out['ignoradas'][] = "$aba!$nl: linha fora do formato esperado (\"".mb_substr(implode(' | ', $preench), 0, 80).'").';
            }
        }
    }

    private function chaveResumo(string $h): ?string
    {
        foreach (self::RESUMO as $p => $k) {
            if ($h === $p || str_starts_with($h, $p.' ')) {
                return $k;
            }
        }

        return null;
    }

    private function lerConferencia(array $linhas): array
    {
        $out = [];
        foreach ($linhas as $l) {
            if (! ctype_digit(trim((string) ($l[0] ?? ''))) || trim((string) ($l[2] ?? '')) === '' || trim((string) ($l[3] ?? '')) === '') {
                continue;
            }
            $out[] = ['tabela' => $l[0], 'cat' => self::CONFERENCIA[self::norm($l[1] ?? '')] ?? self::norm($l[1] ?? ''), 'secao' => $l[2], 'territorio' => $l[3],
                'declarado' => $l[5] ?? '', 'contado' => $l[6] ?? ''];
        }

        return $out;
    }

    /** @return array{ok: bool, relatorio: list<string>} */
    public function executar(string $bytes, Usuario $u, bool $simular): array
    {
        $this->saida = [];
        // Mesmas permissões da tela, sem atalho: catálogo/referências são globais; unidades, locais e entradas valem para toda a
        // árvore do PRB. O papel ADMIN sozinho NÃO tem estoque.entrada (segregação): quem assina precisa também de GESTOR/ARMEIRO no PRB.
        $raiz = Unidade::where('sigla', 'PRB')->whereHas('organizacao', fn ($q) => $q->where('sigla', 'PRB'))->first();
        foreach (['catalogo.gerir' => null, 'referencias.gerir' => null, 'organizacao.gerir' => $raiz, 'estoque.entrada' => $raiz] as $p => $un) {
            if (! $u->ativo || ($un ? ! $this->authz->pode($u, $p, $un) : ! $this->authz->global($u, $p))) {
                throw new SigarbException('SEM_PERMISSAO', "O usuário #{$u->id} precisa estar ativo e ter \"$p\" ".($un ? 'na unidade PRB (raiz) ou global' : 'em escopo global').'.', 403);
            }
        }
        Auth::setUser($u); // auditoria e responsável das movimentações = quem assina a carga
        $dados = $this->ler($bytes);
        $erros = [];
        $res = [];

        DB::beginTransaction();
        try {
            $prb = Organizacao::where('sigla', 'PRB')->first() ?? throw new SigarbException('ESTRUTURA_AUSENTE', 'Organização PRB não encontrada.', 422);
            $locais = $this->locais($prb, $dados['registros'], $erros);
            $vistos = [];
            foreach ($dados['registros'] as $r) {
                $res[] = $this->carregarLinha($r, $locais, $vistos, $erros, $u);
            }
            $carregados = count(array_filter($res, fn ($x) => $x === 'novo'));
            $this->auditoria->registrar('inventario.carga_inicial', $erros ? 'FALHA' : 'SUCESSO', dados: [
                'documento' => self::DOCUMENTO, 'linhas' => count($dados['registros']), 'novas' => $carregados, 'erros' => count($erros), 'simulacao' => $simular]);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $simular || $erros ? DB::rollBack() : DB::commit();

        $this->relatorio($dados, $res, $erros, $locais, $simular);

        return ['ok' => ! $erros, 'relatorio' => $this->saida];
    }

    /** @return array<string, LocalEstoque> grupo => local */
    private function locais(Organizacao $prb, array $registros, array &$erros): array
    {
        $out = [];
        foreach (array_unique(array_column($registros, 'grupo')) as $g) {
            [$codigo, $nome, $tipo, $sigla] = self::LOCAIS[$g] ?? [null, null, null, null];
            if ($codigo === null) {
                $erros[] = "Grupo \"$g\" sem local mapeado (CargaInventarioService::LOCAIS).";

                continue;
            }
            $un = Unidade::where('organizacao_id', $prb->id)->where('sigla', $sigla)->first() ?? $this->criarUnidade($prb, $sigla);
            $local = LocalEstoque::firstOrCreate(['codigo' => $codigo], ['unidade_id' => $un->id, 'nome' => $nome, 'tipo' => $tipo, 'ativo' => true]);
            if ($local->wasRecentlyCreated) {
                $this->auditoria->registrar('local.criado', 'SUCESSO', 'local_estoque', $local->id, $un->id, $local->only(['unidade_id', 'codigo', 'nome', 'tipo']) + ['documento' => self::DOCUMENTO]);
            } elseif ($local->unidade_id !== $un->id) {
                $erros[] = "Local $codigo já existe em outra unidade (#{$local->unidade_id}); esperado $sigla.";
            }
            $out[$g] = $local;
        }

        return $out;
    }

    private function criarUnidade(Organizacao $prb, string $sigla): Unidade
    {
        [$nome, $superior] = self::UNIDADES_NOVAS[$sigla] ?? throw new SigarbException('ESTRUTURA_AUSENTE', "Unidade $sigla não existe na estrutura.", 422);
        $sup = Unidade::where('organizacao_id', $prb->id)->where('sigla', $superior)->firstOrFail();
        $d = ['organizacao_id' => $prb->id, 'unidade_superior_id' => $sup->id, 'sigla' => $sigla, 'nome' => $nome, 'tipo' => 'SUBUNIDADE'];
        $un = Unidade::create($d); // caminho materializado pelo próprio modelo
        $this->auditoria->registrar('unidade.criada', 'SUCESSO', 'unidade', $un->id, $un->id, $d + ['documento' => self::DOCUMENTO]);

        return $un;
    }

    /** @return string novo | existente | erro */
    private function carregarLinha(array $r, array $locais, array &$vistos, array &$erros, Usuario $u): string
    {
        $onde = "{$r['aba']}!{$r['linha']}";
        $local = $locais[$r['grupo']] ?? null;
        if (! $local) {
            return 'erro';
        }
        try {
            $mat = $this->material($r);
            $semSerie = in_array(self::norm($r['serie']), ['', 'S/N', 'SN', 'SEM PATRIMONIO', '-'], true);
            $obs = array_filter([
                "Carga inicial: aba {$r['aba']}, linha {$r['linha']}, seção \"{$r['secao']}\", nº {$r['num']}",
                $r['grupo'] === 'LOCAL NAO INFORMADO' ? 'LOCAL NÃO INFORMADO no documento; guardado no Estoque SMB' : null,
                $r['situacao'] !== '' ? "Situação: {$r['situacao']}" : null,
                $r['tamanho'] !== '' ? "Tamanho: {$r['tamanho']}" : null,
                $r['venc'] !== '' ? "Venc. refil: {$r['venc']}" : null,
                $r['tei'] !== '' ? "TEI: {$r['tei']}" : null, $r['issi'] !== '' ? "ISSI: {$r['issi']}" : null,
                $r['patrimonio'] !== '' ? "Patrimônio: {$r['patrimonio']}" : null,
                $semSerie && $r['serie'] !== '' ? "Numeração no documento: {$r['serie']}" : null,
                $r['obs'] !== '' ? "Obs.: {$r['obs']}" : null,
            ]);
            $d = ['local_destino_id' => $local->id, 'documento_referencia' => self::DOCUMENTO,
                'origem_descricao' => 'Carga inicial do inventário 2026'.($r['origem'] !== '' ? " (origem no documento: {$r['origem']})" : ''),
                'justificativa' => mb_substr(implode(' · ', $obs), 0, 500)];

            if ($mat->modo_controle === Material::LOTE) {
                $qtd = (int) $r['qtd'];
                if (! ctype_digit($r['qtd']) || $qtd < 1) {
                    throw new SigarbException('QTD_INVALIDA', "Quantidade \"{$r['qtd']}\" inválida.", 422);
                }
                // shortcut: chave pela posição (local + material + nº na seção); se o documento for renumerado, conferir antes de recarregar.
                $chave = "inv2026:{$local->codigo}:{$mat->codigo}:{$r['num']}";
                $d['itens'] = [['material_id' => $mat->id, 'lote_codigo' => self::LOTE, 'quantidade' => $qtd]];
            } else {
                if ((int) $r['qtd'] !== 1) {
                    throw new SigarbException('QTD_INVALIDA', "Item individual com quantidade \"{$r['qtd']}\" (esperado 1).", 422);
                }
                // Identificador é gerado pelo EstoqueService. Idempotência: item com série = (material, série) já cadastrado;
                // sem série = chave da posição no documento. A chave mantém o formato das cargas anteriores
                // (prefixo antigo + série / posição), para que recarregar após a migração dos códigos não duplique.
                $prefixo = self::PREFIXOS[$r['cat']];
                $chave = 'inv2026:'.($semSerie ? "$prefixo-SN-{$local->codigo}-L{$r['linha']}"
                    : $prefixo.'-'.trim(preg_replace('/[^A-Za-z0-9.\/-]+/', '-', $r['serie']), '-'));
                if (! $semSerie) {
                    if (isset($vistos[$mat->id.'|'.$r['serie']])) {
                        throw new SigarbException('DUPLICADO', "Duplicidade de série \"{$r['serie']}\" (já em {$vistos[$mat->id.'|'.$r['serie']]}).", 422);
                    }
                    $vistos[$mat->id.'|'.$r['serie']] = $onde;
                    if (ItemIndividual::where('material_id', $mat->id)->where('numero_serie', $r['serie'])->exists()) {
                        return 'existente';
                    }
                }
                if (Movimentacao::where('chave_idempotencia', $chave)->exists()) {
                    return 'existente';
                }
                $d['itens'] = [array_filter(['material_id' => $mat->id, 'numero_serie' => $semSerie ? null : $r['serie'],
                    // P-03: baixado ou vencido não pode ser entregue/reservado enquanto aguarda destinação.
                    'estado_conservacao' => preg_match('/BAIXAD|VENCID/', self::norm($r['situacao'])) ? 'INSERVIVEL' : null])];
            }
            [, $replay] = $this->estoque->registrarEntrada($u, $d, $chave);

            return $replay ? 'existente' : 'novo';
        } catch (ValidationException $e) {
            $erros[] = "$onde: ".implode(' ', array_merge(...array_values($e->errors())));
        } catch (SigarbException $e) {
            $erros[] = "$onde: [{$e->codigo}] {$e->getMessage()}";
        }

        return 'erro';
    }

    private function material(array $r): Material
    {
        $desc = $r['descricao'];
        $nd = self::norm($desc);
        $cat = $r['cat'];
        $m = ['modo_controle' => Material::INDIVIDUAL, 'unidade_medida' => 'UN'];
        $grupo = $cat === 'TASER' && $r['acessorio'] ? 'ACESSORIO_TASER' : $cat;
        $slug = fn (string $s) => rtrim(substr(Str::upper(Str::slug($s)), 0, 40), '-');
        switch (true) {
            case $cat === 'ARMAMENTO':
                $m += ['codigo' => $slug($desc), 'nome' => $desc, 'modelo' => trim(preg_replace('/\s+CAL\b.*$/i', '', $desc)),
                    'fabricante' => str_contains($nd, 'TAURUS') ? 'Taurus' : null, 'calibre' => str_contains($nd, '.40') ? '.40' : null];
                break;
            case $cat === 'TASER' && $r['acessorio']:
                $m = ['modo_controle' => Material::LOTE, 'unidade_medida' => 'UN', 'codigo' => $slug($desc), 'nome' => $desc];
                break;
            case $cat === 'TASER':
                $m += ['codigo' => $slug($desc), 'nome' => $desc, 'fabricante' => 'Axon', 'modelo' => str_contains($nd, 'X2') ? 'X2' : null];
                break;
            case $cat === 'MUNICAO':
                // Perfil MUNICAO exige fabricante, que o documento não informa: tipo oficial "Munição letal" tem perfil GERAL.
                $m = ['modo_controle' => Material::LOTE, 'unidade_medida' => 'UN', 'codigo' => $slug($desc), 'nome' => $desc, 'calibre' => str_contains($nd, '.40') ? '.40' : null];
                break;
            case $cat === 'COLETE':
                // Perfil COLETE exige nível de proteção e data de fabricação, ausentes no documento: tipo oficial "Colete balístico" é GERAL; um material por tamanho.
                $tam = self::norm($r['tamanho']) ?: (preg_match('/[“"]([PMG]{1,2})[”"]/u', $r['obs'], $x) ? $x[1] : '');
                if (! in_array($tam, ['P', 'M', 'G', 'GG'], true)) {
                    throw new SigarbException('TAMANHO_AUSENTE', 'Colete sem tamanho (P/M/G) na linha.', 422);
                }
                $m += ['codigo' => "COLETE-BALISTICO-$tam", 'nome' => "Colete balístico - tamanho $tam", 'tamanho' => $tam];
                break;
            case $cat === 'ALGEMA':
                $m += ['codigo' => 'ALGEMA-PULSO-ACO', 'nome' => 'Algema de pulso em aço']; // "Algema"/"Algemas" no documento
                break;
            default: // COMUNICACAO
                $m += ['codigo' => 'RADIO-HT-DIGITAL-HYTERA', 'nome' => 'Rádio HT digital Hytera', 'fabricante' => str_contains($nd, 'HYTERA') ? 'Hytera' : null];
        }
        if (strlen($m['codigo']) < 2) {
            throw new SigarbException('DESCRICAO_AUSENTE', 'Linha sem descrição do material.', 422);
        }
        if ($existente = Material::where('codigo', $m['codigo'])->first()) {
            return $existente;
        }
        [$codCat, $nomeTipo] = CatalogoOficial::CARGA[$grupo];
        $categoria = $this->categoria($codCat);
        $t = TipoMaterial::firstOrCreate(['categoria_id' => $categoria->id, 'nome' => $nomeTipo], ['perfil' => CatalogoOficial::perfil($codCat, $nomeTipo), 'ativo' => true]);
        if ($t->wasRecentlyCreated) {
            $this->auditoria->registrar('referencia.tipos-material.criada', 'SUCESSO', 'tipos-material', $t->id, dados: ['categoria_id' => $categoria->id, 'nome' => $t->nome, 'perfil' => $t->perfil]);
        }
        $d = array_filter([
            'categoria_id' => $categoria->id, 'tipo_id' => $t->id,
            'fabricante_id' => isset($m['fabricante']) ? $this->referencia(Fabricante::class, 'fabricantes', $m['fabricante']) : null,
            'calibre_id' => isset($m['calibre']) ? $this->referencia(Calibre::class, 'calibres', $m['calibre']) : null,
        ] + array_diff_key($m, ['fabricante' => 1, 'calibre' => 1]), fn ($v) => $v !== null);
        PerfilMaterial::validarMaterial($t->perfil, $d); // mesmas regras de perfil da tela
        $mat = Material::create($d + ['ativo' => true]);
        $this->auditoria->registrar('material.criado', 'SUCESSO', 'material', $mat->id, dados: $d + ['documento' => self::DOCUMENTO]);

        return $mat->load('tipo');
    }

    private function categoria(string $codigo): Categoria
    {
        [$nome, $natureza, $prefixo] = CatalogoOficial::CATEGORIAS[$codigo];
        $c = Categoria::firstOrCreate(['codigo' => $codigo], ['nome' => $nome, 'natureza' => $natureza, 'prefixo_codigo' => $prefixo, 'ativo' => true]);
        if ($c->wasRecentlyCreated) { // como ReferenciaController::store: toda categoria nasce com o tipo "Geral"
            $this->auditoria->registrar('referencia.categorias.criada', 'SUCESSO', 'categorias', $c->id, dados: ['codigo' => $codigo, 'nome' => $nome, 'natureza' => $natureza]);
            $g = TipoMaterial::firstOrCreate(['categoria_id' => $c->id, 'nome' => 'Geral'], ['perfil' => 'GERAL', 'ativo' => true]);
            $this->auditoria->registrar('referencia.tipos-material.criada', 'SUCESSO', 'tipos-material', $g->id, dados: ['categoria_id' => $c->id, 'nome' => 'Geral', 'perfil' => 'GERAL']);
        }

        return $c;
    }

    private function referencia(string $modelo, string $tipo, string $nome): int
    {
        $m = $modelo::firstOrCreate(['nome' => $nome], ['ativo' => true]);
        if ($m->wasRecentlyCreated) {
            $this->auditoria->registrar("referencia.$tipo.criada", 'SUCESSO', $tipo, $m->id, dados: ['nome' => $nome]);
        }

        return $m->id;
    }

    private function relatorio(array $dados, array $res, array $erros, array $locais, bool $simular): void
    {
        $p = fn (string $s = '') => $this->saida[] = $s;
        $regs = $dados['registros'];
        $p('RELATÓRIO DA CARGA — '.self::DOCUMENTO);
        $p('Modo: '.($simular ? 'SIMULAÇÃO (transação desfeita; nada gravado)' : ($erros ? 'REAL — ABORTADA por erros (transação desfeita; nada gravado)' : 'REAL — gravada')));
        $p(sprintf('Linhas de item lidas: %d · novas: %d · já carregadas (idempotência): %d · com erro: %d',
            count($regs), count(array_keys($res, 'novo')), count(array_keys($res, 'existente')), count(array_keys($res, 'erro'))));

        $p();
        $p('== Mapeamento grupo do documento → seções → local → unidade ==');
        foreach ($locais as $g => $l) {
            $secoes = array_unique(array_column(array_filter($regs, fn ($r) => $r['grupo'] === $g), 'secao'));
            $p(sprintf('%-28s → %-16s (%s) → %s', $g, $l->codigo, $l->tipo, self::LOCAIS[$g][3]));
            foreach ($secoes as $s) {
                $p("      seção: $s");
            }
        }

        $p();
        $p('== Contagem por local e categoria (importador) × quadro Resumo da aba ==');
        $chave = fn (array $r) => match ($r['cat']) {
            'ARMAMENTO' => 'PISTOLAS', 'TASER' => $r['acessorio'] ? 'ACESSORIOS TASER' : 'TASERS', 'MUNICAO' => 'MUNICOES',
            'COLETE' => 'COLETES '.(self::norm($r['tamanho']) ?: '?'), 'ALGEMA' => 'ALGEMAS', default => 'RADIOS'
        };
        $cont = [];
        foreach ($regs as $r) {
            $cont[$r['grupo']][$chave($r)] = ($cont[$r['grupo']][$chave($r)] ?? 0) + (int) $r['qtd'];
        }
        foreach ($dados['resumos'] + $cont as $g => $_) {
            $local = isset($locais[$g]) ? $locais[$g]->codigo : '(sem local)';
            foreach (array_unique(array_merge(array_keys($dados['resumos'][$g] ?? []), array_keys($cont[$g] ?? []))) as $k) {
                $dec = $dados['resumos'][$g][$k] ?? null;
                $c = $cont[$g][$k] ?? 0;
                if ($dec === 0 && $c === 0) {
                    continue;
                }
                $p(sprintf('%-28s %-16s %-17s contado=%5d  resumo=%5s  %s', $g, $local, $k, $c, $dec ?? '-', $dec === $c ? 'OK' : 'DIVERGE'));
            }
        }

        $p();
        $p('== Subtotais por tabela da aba (soma das linhas × SUBTOTAL) ==');
        $div = 0;
        foreach ($dados['subtotais'] as $b) {
            if ($b['declarado'] !== $b['soma']) {
                $div++;
                $p(sprintf('DIVERGE %s!%d %s: soma=%d subtotal=%s', $b['aba'], $b['linha'], $b['secao'], $b['soma'], $b['declarado'] ?? '-'));
            }
        }
        $p(count($dados['subtotais'])." tabelas, $div divergência(s).");

        $p();
        $p('== Aba Conferência: total declarado no documento × contado (planilha) × contado (importador) ==');
        foreach ($dados['conferencia'] as $c) {
            $imp = array_sum(array_map(fn ($r) => (int) $r['qtd'], array_filter($regs, fn ($r) => self::norm($r['secao']) === self::norm($c['secao'])
                && ($r['acessorio'] ? 'ACESSORIO' : $r['cat']) === $c['cat'])));
            $status = match (true) {
                (string) $imp !== (string) $c['contado'] => 'IMPORTADOR≠PLANILHA',
                ! is_numeric($c['declarado']) => 'SEM TOTAL NO DOCUMENTO',
                (int) $c['declarado'] === $imp => 'OK',
                default => 'DIVERGE DO DOCUMENTO',
            };
            $p(sprintf('#%-3s %-11s %-58s declarado=%-18s planilha=%-5s importador=%-5d %s', $c['tabela'], $c['cat'], mb_substr($c['secao'], 0, 58), $c['declarado'], $c['contado'], $imp, $status));
        }

        $p();
        $p('== Duplicidades de série e erros por linha ==');
        foreach ($erros ?: ['(nenhum)'] as $e) {
            $p($e);
        }

        $p();
        $p('== Linhas ignoradas ==');
        foreach ($dados['ignoradas'] ?: ['(nenhuma)'] as $e) {
            $p($e);
        }

        if ($dados['notas']) {
            $p();
            $p('== Pontos de atenção registrados no próprio documento (aba Resumo) ==');
            foreach ($dados['notas'] as $n) {
                $p($n);
            }
        }
    }
}
