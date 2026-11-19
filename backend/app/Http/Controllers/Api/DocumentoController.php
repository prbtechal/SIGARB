<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\DocumentoMetadadosRequest;
use App\Http\Requests\DocumentoUploadRequest;
use App\Models\Documento;
use App\Models\DocumentoVinculo;
use App\Models\LocalEstoque;
use App\Models\Movimentacao;
use App\Models\TipoDocumento;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Services\DocumentoService;
use App\Support\Busca;
use App\Support\SigarbException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * RF-014, RN-019, RN-021. Toda operação: sessão + conta ativa (middleware) + permissão + escopo + auditoria.
 * Documento fora do escopo responde 404 (não revela existência) e a tentativa é auditada.
 */
class DocumentoController extends ApiController
{
    public function __construct(private DocumentoService $docs) {}

    public function tipos(): JsonResponse
    {
        $this->exigir('documentos.ver');

        return response()->json(['data' => TipoDocumento::where('ativo', true)->orderBy('nome')->get()]);
    }

    /**
     * Raiz de busca (2026-11-19): todo documento do escopo, de qualquer tela (envio avulso, anexo da entrada/saída, nova versão).
     * `q` casa palavra por palavra (sem acento, parcial) em título, arquivo, número, tipo, quem enviou, origem/destinação e
     * documento do registro, material/série do registro e "#N"/número do registro.
     */
    public function index(Request $r): JsonResponse
    {
        $this->exigir('documentos.ver');
        $f = $r->validate([
            'q' => ['nullable', 'string', 'max:80'], 'tipo_documento_id' => ['nullable', 'integer'], 'unidade_id' => ['nullable', 'integer'],
            'situacao' => ['nullable', 'in:ATIVO,ARQUIVADO'], 'movimentacao_id' => ['nullable', 'integer'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'],
            'meus' => ['nullable', 'boolean'], 'registro_tipo' => ['nullable', 'in:ENTRADA,SAIDA,BAIXA,SEM_REGISTRO,OUTRO'],
            'versoes' => ['nullable', 'in:ATUAIS,TODAS'],
        ]);
        $q = Documento::query()->with(['tipo:id,codigo,nome', 'unidade:id,sigla', 'enviadoPor:id,nome', 'sucessora:id,versao_anterior_id,codigo_publico,versao',
            'vinculos.movimentacao:id,tipo,origem_descricao,documento_referencia,local_origem_id,local_destino_id,ocorrido_em']);
        $this->authz()->escopar($q->getQuery(), $r->user(), 'documentos.ver');
        // Busca e filtro pelo registro ligado só olham registros que o usuário enxerga no estoque (não revela conteúdo de fora).
        $visivel = function ($m) use ($r) {
            $locais = $this->authz()->locaisVisiveis($r->user(), 'estoque.ver');
            $m->where(fn ($w) => $w->whereIn('local_destino_id', $locais)->orWhere(fn ($x) => $x->whereNull('local_destino_id')->whereIn('local_origem_id', $locais)));
        };
        if (! empty($f['q'])) {
            $this->buscar($q, $f['q'], $visivel);
        }
        foreach (['tipo_documento_id', 'unidade_id', 'situacao'] as $k) {
            if (isset($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['movimentacao_id'])) {
            $q->whereHas('vinculos', fn ($w) => $w->where('movimentacao_id', $f['movimentacao_id']));
        }
        if (! empty($f['de'])) {
            $q->where('created_at', '>=', $f['de']);
        }
        if (! empty($f['ate'])) {
            $q->where('created_at', '<', date('Y-m-d', strtotime($f['ate'].' +1 day')));
        }
        if (! empty($f['meus'])) {
            $q->where('enviado_por_id', $r->user()->id);
        }
        match ($f['registro_tipo'] ?? null) {
            null => null,
            'SEM_REGISTRO' => $q->whereDoesntHave('vinculos'),
            'OUTRO' => $q->whereHas('vinculos.movimentacao', fn ($w) => $visivel($w->whereNotIn('tipo', ['ENTRADA', 'SAIDA', 'BAIXA']))),
            default => $q->whereHas('vinculos.movimentacao', fn ($w) => $visivel($w->where('tipo', $f['registro_tipo']))),
        };
        // Padrão: só a versão atual de cada documento (as anteriores continuam acessíveis pelo detalhe e por versoes=TODAS).
        if (($f['versoes'] ?? 'ATUAIS') === 'ATUAIS') {
            $q->whereDoesntHave('sucessora');
        }
        $u = $r->user();

        return $this->paginar($r, $q, fn (Documento $d) => $this->resumo($d) + ['registros' => $this->registrosVisiveis($u, $d)], ['id', 'titulo', 'created_at'], '-id');
    }

    /** Busca palavra por palavra (App\Support\Busca): cada palavra precisa casar em algum dos campos. */
    private function buscar(Builder $q, string $termo, callable $visivel): void
    {
        foreach (Busca::termos($termo) as $p) {
            $like = Busca::like($p);
            $num = preg_match('/^#?(\d{1,10})$/', $p, $m) ? (int) $m[1] : null;
            $q->where(function ($w) use ($like, $num, $visivel) {
                $w->where('titulo', 'like', $like)->orWhere('nome_original', 'like', $like)->orWhere('numero_referencia', 'like', $like)
                    ->orWhereHas('tipo', fn ($t) => $t->where('nome', 'like', $like))
                    ->orWhereHas('enviadoPor', fn ($t) => $t->where('nome', 'like', $like))
                    ->orWhereHas('vinculos.movimentacao', function ($m) use ($like, $num, $visivel) {
                        $visivel($m);
                        $m->where(fn ($x) => $x->where('origem_descricao', 'like', $like)->orWhere('documento_referencia', 'like', $like)
                            ->when($num !== null, fn ($y) => $y->orWhere('movimentacoes.id', $num))
                            ->orWhereHas('itens', fn ($i) => $i
                                ->whereIn('lote_id', DB::table('lotes as l')->join('materiais as mt', 'mt.id', '=', 'l.material_id')
                                    ->where(fn ($z) => $z->where('mt.nome', 'like', $like)->orWhere('l.codigo', 'like', $like))->select('l.id'))
                                ->orWhereIn('item_individual_id', DB::table('itens_individuais as ii')->join('materiais as mt', 'mt.id', '=', 'ii.material_id')
                                    ->where(fn ($z) => $z->where('mt.nome', 'like', $like)->orWhere('ii.numero_serie', 'like', $like)->orWhere('ii.identificador', 'like', $like))
                                    ->select('ii.id'))));
                    });
            });
        }
    }

    /** Registros ligados ao documento que o usuário enxerga (estoque.ver na unidade, sem depósito oculto). */
    private function registrosVisiveis(Usuario $u, Documento $d): array
    {
        $out = [];
        foreach ($d->vinculos as $v) {
            $m = $v->movimentacao;
            if ($m && $this->movimentacaoVisivel($u, $m)) {
                $out[] = ['movimentacao_id' => $m->id, 'tipo' => $m->tipo, 'origem' => $m->origem_descricao, 'documento_referencia' => $m->documento_referencia];
            }
        }

        return $out;
    }

    private function unidadeDaMovimentacao(Movimentacao $m): ?int
    {
        $id = LocalEstoque::whereKey($m->local_destino_id ?? $m->local_origem_id)->value('unidade_id');

        return $id === null ? null : (int) $id;
    }

    private function movimentacaoVisivel(Usuario $u, Movimentacao $m): bool
    {
        $unidade = $this->unidadeDaMovimentacao($m);

        return $unidade !== null && $this->authz()->pode($u, 'estoque.ver', $unidade)
            && $this->authz()->localVisivel($u, $m->local_origem_id) && $this->authz()->localVisivel($u, $m->local_destino_id);
    }

    /**
     * Registros de ENTRADA, SAÍDA e BAIXA (não estornados) salvos sem documento, nos locais onde o usuário vê o estoque e
     * pode enviar documentos. `pode_anexar`: quem registrou, ou quem tem documentos.gerir na unidade.
     */
    public function registrosSemDocumento(Request $r): JsonResponse
    {
        $this->exigir('documentos.enviar');
        $f = $r->validate(['q' => ['nullable', 'string', 'max:80'], 'tipo' => ['nullable', 'in:ENTRADA,SAIDA,BAIXA'], 'meus' => ['nullable', 'boolean']]);
        $u = $r->user();
        $locais = $this->authz()->locaisVisiveis($u, 'estoque.ver');
        $envio = $this->authz()->unidadesPermitidas($u, 'documentos.enviar');
        if ($envio !== null) {
            $locais->whereIn('unidade_id', $envio === [] ? [0] : $envio);
        }
        $q = Movimentacao::query()->with(['responsavel:id,nome', 'localOrigem:id,codigo,unidade_id', 'localDestino:id,codigo,unidade_id'])->withCount('itens')
            ->whereIn('tipo', ! empty($f['tipo']) ? [$f['tipo']] : ['ENTRADA', 'SAIDA', 'BAIXA'])
            ->whereDoesntHave('documentoVinculos')->whereDoesntHave('estorno')
            ->where(fn ($w) => $w->where(fn ($x) => $x->whereNotNull('local_destino_id')->whereIn('local_destino_id', $locais))
                ->orWhere(fn ($x) => $x->whereNull('local_destino_id')->whereIn('local_origem_id', $locais)));
        if (! empty($f['meus'])) {
            $q->where('responsavel_id', $u->id);
        }
        if (! empty($f['q'])) {
            foreach (Busca::termos($f['q']) as $p) {
                $like = Busca::like($p);
                $num = preg_match('/^#?(\d{1,10})$/', $p, $m) ? (int) $m[1] : null;
                $q->where(fn ($w) => $w->where('origem_descricao', 'like', $like)->orWhere('documento_referencia', 'like', $like)
                    ->when($num !== null, fn ($y) => $y->orWhere('id', $num)));
            }
        }

        return $this->paginar($r, $q, function (Movimentacao $m) use ($u) {
            $local = $m->localDestino ?? $m->localOrigem;

            return [
                'id' => $m->id, 'tipo' => $m->tipo, 'ocorrido_em' => $m->ocorrido_em->toIso8601String(),
                'origem' => $m->origem_descricao, 'documento_referencia' => $m->documento_referencia,
                'local' => $local?->only(['id', 'codigo']), 'itens_count' => $m->itens_count,
                'responsavel' => $m->responsavel?->nome,
                'pode_anexar' => $m->responsavel_id === $u->id || $this->authz()->pode($u, 'documentos.gerir', $local?->unidade_id),
            ];
        }, ['id', 'ocorrido_em'], '-ocorrido_em');
    }

    /**
     * Anexa um arquivo a um registro já salvo (entrada/saída sem anexo, ou anexo opcional enviado logo após registrar).
     * Fora do escopo = 404 (não revela); sem documentos.enviar = 403; registro de outra pessoa exige documentos.gerir (403).
     * A unidade do documento é a do local do registro (o cliente não escolhe).
     */
    public function anexarAoRegistro(Request $r, int $movimentacao): JsonResponse
    {
        $u = $r->user();
        $mov = Movimentacao::find($movimentacao);
        if (! $mov || ! $this->movimentacaoVisivel($u, $mov)) {
            $this->auditoria()->registrar('documento.anexo_negado', 'NEGADO', 'movimentacao', $movimentacao, null, ['motivo' => 'fora_do_escopo']);
            throw new SigarbException('NAO_ENCONTRADO', 'Registro não encontrado.', 404);
        }
        $unidade = $this->unidadeDaMovimentacao($mov);
        if (! $this->authz()->pode($u, 'documentos.enviar', $unidade)) {
            $this->auditoria()->registrar('documento.anexo_negado', 'NEGADO', 'movimentacao', $mov->id, $unidade, ['motivo' => 'sem_permissao']);
            throw new SigarbException('ACESSO_NEGADO', 'Você não tem permissão para enviar documentos nesta unidade.', 403);
        }
        if ($mov->responsavel_id !== $u->id && ! $this->authz()->pode($u, 'documentos.gerir', $unidade)) {
            $this->auditoria()->registrar('documento.anexo_negado', 'NEGADO', 'movimentacao', $mov->id, $unidade, ['motivo' => 'registro_de_outra_pessoa']);
            throw new SigarbException('ANEXO_SO_RESPONSAVEL', 'Só quem registrou ou a gestão de documentos pode anexar documento a este registro.', 403);
        }
        $kb = intdiv(config('sigarb.documentos.max_bytes'), 1024);
        $d = $r->validate([
            'arquivo' => ['required', 'file', "max:$kb"],
            'tipo_documento_id' => ['nullable', 'integer', Rule::exists('tipos_documento', 'id')->where('ativo', true)],
            'titulo' => ['nullable', 'string', 'min:3', 'max:160'],
            'numero_referencia' => ['nullable', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:500'],
        ], ['arquivo.max' => 'O arquivo excede o limite permitido.', 'arquivo.required' => 'Selecione um arquivo.']);
        $rotulo = ['ENTRADA' => 'Entrada', 'SAIDA' => 'Saída', 'BAIXA' => 'Baixa'][$mov->tipo] ?? (config("sigarb.operacoes.{$mov->tipo}.rotulo") ?? $mov->tipo);
        $padraoTipo = ['ENTRADA' => 'NOTA_RECEBIMENTO', 'SAIDA' => 'TERMO', 'BAIXA' => 'TERMO'][$mov->tipo] ?? 'OUTRO';
        $d['tipo_documento_id'] ??= TipoDocumento::where('codigo', $padraoTipo)->where('ativo', true)->value('id')
            ?? TipoDocumento::where('ativo', true)->orderBy('id')->value('id');
        if (! $d['tipo_documento_id']) {
            throw new SigarbException('TIPO_DOCUMENTO_INDISPONIVEL', 'Nenhum tipo de documento ativo. Peça o cadastro à administração.', 422);
        }
        $d['titulo'] ??= mb_substr($rotulo.' #'.$mov->id.($mov->documento_referencia ? ' — '.$mov->documento_referencia : ''), 0, 160);
        $d['numero_referencia'] ??= $mov->documento_referencia;
        $d['unidade_id'] = $unidade;
        $d['movimentacao_id'] = $mov->id;
        $d['origem_envio'] = 'registro';
        $doc = $this->docs->armazenar($u, $r->file('arquivo'), $d);

        return response()->json($this->detalhe($r, $doc->fresh()), 201);
    }

    /**
     * Nova versão de um documento que a própria pessoa enviou (o arquivo antigo nunca é substituído: ADR-011).
     * Herda tipo, unidade, título, número, descrição e vínculos; a anterior continua consultável.
     */
    public function novaVersao(Request $r, string $codigo): JsonResponse
    {
        $ant = $this->localizar($r, $codigo, 'documentos.enviar', 'nova_versao');
        $u = $r->user();
        if ($ant->enviado_por_id !== $u->id) {
            $this->auditoria()->registrar('documento.acesso_negado', 'NEGADO', 'documento', $ant->id, $ant->unidade_id, ['operacao' => 'nova_versao', 'motivo' => 'nao_e_quem_enviou']);
            throw new SigarbException('VERSAO_SO_DE_QUEM_ENVIOU', 'Só quem enviou o documento pode enviar uma nova versão.', 403);
        }
        if ($ant->situacao !== 'ATIVO') {
            throw new SigarbException('DOCUMENTO_ARQUIVADO', 'Documento arquivado não recebe nova versão.', 409);
        }
        if ($ant->sucessora()->exists()) {
            throw new SigarbException('JA_TEM_VERSAO_NOVA', 'Este documento já tem uma versão mais nova. Envie a nova versão a partir dela.', 409);
        }
        $kb = intdiv(config('sigarb.documentos.max_bytes'), 1024);
        $d = $r->validate([
            'arquivo' => ['required', 'file', "max:$kb"],
            'descricao' => ['nullable', 'string', 'max:500'],
        ], ['arquivo.max' => 'O arquivo excede o limite permitido.', 'arquivo.required' => 'Selecione um arquivo.']);
        try {
            $doc = $this->docs->armazenar($u, $r->file('arquivo'), [
                'tipo_documento_id' => $ant->tipo_documento_id, 'unidade_id' => $ant->unidade_id, 'titulo' => $ant->titulo,
                'numero_referencia' => $ant->numero_referencia, 'descricao' => $d['descricao'] ?? $ant->descricao,
                'versao' => $ant->versao + 1, 'versao_anterior_id' => $ant->id,
                'movimentacoes' => $ant->vinculos()->pluck('movimentacao_id')->all(), 'origem_envio' => 'nova_versao',
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new SigarbException('JA_TEM_VERSAO_NOVA', 'Este documento já tem uma versão mais nova.', 409);
        }

        return response()->json($this->detalhe($r, $doc->fresh()), 201);
    }

    public function store(DocumentoUploadRequest $r): JsonResponse
    {
        $d = $r->validated();
        $unidade = Unidade::findOrFail($d['unidade_id']);
        if (! $this->authz()->pode($r->user(), 'documentos.enviar', $unidade)) {
            $this->exigir('documentos.enviar', $unidade); // 403 auditado
        }
        if (! $unidade->ativo) {
            throw new SigarbException('UNIDADE_INATIVA', 'A unidade está inativa.', 422);
        }
        if (! empty($d['movimentacao_id'])) {
            $this->exigirMovimentacaoVinculavel($r, (int) $d['movimentacao_id']);
        }
        $doc = $this->docs->armazenar($r->user(), $r->file('arquivo'), $d);

        return response()->json($this->detalhe($r, $doc->fresh()), 201);
    }

    public function show(Request $r, string $codigo): JsonResponse
    {
        $doc = $this->localizar($r, $codigo, 'documentos.ver', 'consulta');
        // Consulta de detalhe é tratada como sensível (RN-020; política provisória P-08).
        $this->auditoria()->registrar('documento.consulta', 'SUCESSO', 'documento', $doc->id, $doc->unidade_id, ['codigo' => $doc->codigo_publico]);

        return response()->json($this->detalhe($r, $doc));
    }

    public function download(Request $r, string $codigo): BinaryFileResponse
    {
        $doc = $this->localizar($r, $codigo, 'documentos.ver', 'download');
        $abs = $this->docs->arquivoVerificado($doc);
        $this->auditoria()->registrar('documento.download', 'SUCESSO', 'documento', $doc->id, $doc->unidade_id, ['codigo' => $doc->codigo_publico, 'sha256' => $doc->sha256]);

        $resp = new BinaryFileResponse($abs, 200, [], false, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        $resp->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $doc->nome_original, 'documento.'.$doc->extensao);
        $resp->headers->set('Content-Type', $doc->mime);
        $resp->headers->set('X-Content-Type-Options', 'nosniff');
        $resp->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $resp->headers->set('Cache-Control', 'no-store, private');
        $resp->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $resp;
    }

    public function update(DocumentoMetadadosRequest $r, string $codigo): JsonResponse
    {
        $doc = $this->localizar($r, $codigo, 'documentos.gerir', 'alteracao');
        if ($doc->situacao === 'ARQUIVADO') {
            throw new SigarbException('DOCUMENTO_ARQUIVADO', 'Documento arquivado não pode ter metadados alterados. Reative-o primeiro.', 409);
        }
        $d = $r->validated();
        $antes = $doc->only(array_keys($d));
        DB::transaction(function () use ($doc, $d, $antes) {
            $doc->update($d);
            $this->auditoria()->registrar('documento.metadados', 'SUCESSO', 'documento', $doc->id, $doc->unidade_id, ['antes' => $antes, 'depois' => $d]);
        });

        return response()->json($this->detalhe($r, $doc->fresh()));
    }

    public function arquivar(Request $r, string $codigo): JsonResponse
    {
        return $this->mudarSituacao($r, $codigo, 'ARQUIVADO');
    }

    public function reativar(Request $r, string $codigo): JsonResponse
    {
        return $this->mudarSituacao($r, $codigo, 'ATIVO');
    }

    public function vincular(Request $r, string $codigo): JsonResponse
    {
        $doc = $this->localizar($r, $codigo, 'documentos.gerir', 'vinculo');
        $d = $r->validate(['movimentacao_id' => ['required', 'integer']]);
        $this->exigirMovimentacaoVinculavel($r, (int) $d['movimentacao_id']);
        try {
            DB::transaction(function () use ($r, $doc, $d) {
                DocumentoVinculo::create(['documento_id' => $doc->id, 'movimentacao_id' => $d['movimentacao_id'], 'vinculado_por_id' => $r->user()->id]);
                $this->auditoria()->registrar('documento.vinculado', 'SUCESSO', 'documento', $doc->id, $doc->unidade_id, ['movimentacao_id' => $d['movimentacao_id']]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new SigarbException('VINCULO_DUPLICADO', 'O documento já está vinculado a esta movimentação.', 422);
        }

        return response()->json($this->detalhe($r, $doc->fresh()), 201);
    }

    private function mudarSituacao(Request $r, string $codigo, string $nova): JsonResponse
    {
        $doc = $this->localizar($r, $codigo, 'documentos.gerir', 'ciclo_de_vida');
        $just = $r->validate(['justificativa' => ['required', 'string', 'min:10', 'max:500']])['justificativa'];
        if ($doc->situacao === $nova) {
            throw new SigarbException('SITUACAO_INALTERADA', 'O documento já está nesta situação.', 409);
        }
        DB::transaction(function () use ($r, $doc, $nova, $just) {
            $doc->update(['situacao' => $nova, 'arquivado_em' => $nova === 'ARQUIVADO' ? now() : null, 'arquivado_por_id' => $nova === 'ARQUIVADO' ? $r->user()->id : null]);
            $this->auditoria()->registrar($nova === 'ARQUIVADO' ? 'documento.arquivado' : 'documento.reativado', 'SUCESSO', 'documento', $doc->id, $doc->unidade_id, ['justificativa' => $just]);
        });

        return response()->json($this->detalhe($r, $doc->fresh()));
    }

    /** Resolve por código público e aplica permissão + escopo; fora do escopo = 404 auditado. */
    private function localizar(Request $r, string $codigo, string $perm, string $operacao): Documento
    {
        $doc = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $codigo) ? Documento::where('codigo_publico', $codigo)->first() : null;
        if ($doc && $this->authz()->pode($r->user(), $perm, $doc->unidade_id)) {
            return $doc->load(['tipo:id,codigo,nome', 'unidade:id,sigla,nome', 'enviadoPor:id,nome']);
        }
        $enxerga = $doc && $this->authz()->pode($r->user(), 'documentos.ver', $doc->unidade_id);
        $this->auditoria()->registrar('documento.acesso_negado', 'NEGADO', 'documento', $doc?->id, $enxerga ? $doc->unidade_id : null, [
            'operacao' => $operacao, 'permissao' => $perm, 'existe' => (bool) $doc,
        ]);
        // Quem já enxerga o documento recebe 403; os demais, 404 (não revela existência).
        throw $enxerga
            ? new SigarbException('ACESSO_NEGADO', 'Você não tem permissão para esta ação neste documento.', 403)
            : new SigarbException('NAO_ENCONTRADO', 'Documento não encontrado.', 404);
    }

    private function exigirMovimentacaoVinculavel(Request $r, int $movId): void
    {
        $m = Movimentacao::find($movId);
        $unidade = $m ? LocalEstoque::whereKey($m->local_destino_id ?? $m->local_origem_id)->value('unidade_id') : null;
        if (! $m || ! $this->authz()->pode($r->user(), 'estoque.ver', $unidade)) {
            $this->auditoria()->registrar('documento.vinculo_negado', 'NEGADO', 'movimentacao', $movId);
            throw new SigarbException('MOVIMENTACAO_INACESSIVEL', 'Movimentação inexistente ou fora do seu escopo.', 422);
        }
    }

    private function resumo(Documento $d): array
    {
        return [
            'codigo' => $d->codigo_publico, 'titulo' => $d->titulo, 'numero_referencia' => $d->numero_referencia,
            'tipo' => $d->tipo?->only(['id', 'codigo', 'nome']), 'unidade' => $d->unidade?->only(['id', 'sigla']),
            'nome_original' => $d->nome_original, 'mime' => $d->mime, 'tamanho_bytes' => $d->tamanho_bytes, 'situacao' => $d->situacao,
            'enviado_por' => $d->enviadoPor?->nome, 'enviado_em' => $d->created_at?->toIso8601String(),
            'versao' => $d->versao ?? 1, 'substituido_por' => $d->sucessora?->codigo_publico,
        ];
    }

    private function detalhe(Request $r, Documento $d): array
    {
        $d->loadMissing(['tipo:id,codigo,nome', 'unidade:id,sigla,nome', 'enviadoPor:id,nome', 'vinculos.movimentacao',
            'sucessora:id,versao_anterior_id,codigo_publico,versao', 'anterior:id,codigo_publico,versao']);
        $u = $r->user();
        $vinc = $d->vinculos->map(function ($v) use ($u) {
            $un = LocalEstoque::whereKey($v->movimentacao->local_destino_id ?? $v->movimentacao->local_origem_id)->value('unidade_id');

            return $this->authz()->pode($u, 'estoque.ver', $un) ? ['movimentacao_id' => $v->movimentacao_id, 'tipo' => $v->movimentacao->tipo] : null;
        });

        return $this->resumo($d) + [
            'descricao' => $d->descricao, 'sha256' => $d->sha256, 'extensao' => $d->extensao,
            'arquivado_em' => $d->arquivado_em?->toIso8601String(),
            'vinculos' => $vinc->filter()->values(), 'vinculos_ocultos' => $vinc->filter(fn ($v) => $v === null)->count(),
            'pode_gerir' => $this->authz()->pode($u, 'documentos.gerir', $d->unidade_id),
            'versao_anterior' => $d->anterior?->codigo_publico,
            // Nova versão: só quem enviou, com documentos.enviar na unidade, documento ativo e ainda sem versão mais nova.
            'pode_nova_versao' => $d->enviado_por_id === $u->id && $d->situacao === 'ATIVO' && ! $d->sucessora
                && $this->authz()->pode($u, 'documentos.enviar', $d->unidade_id),
        ];
    }
}
