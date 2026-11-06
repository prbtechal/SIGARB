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
use App\Services\DocumentoService;
use App\Support\SigarbException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return response()->json(['data' => TipoDocumento::orderBy('nome')->get()]);
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir('documentos.ver');
        $f = $r->validate([
            'q' => ['nullable', 'string', 'max:80'], 'tipo_documento_id' => ['nullable', 'integer'], 'unidade_id' => ['nullable', 'integer'],
            'situacao' => ['nullable', 'in:ATIVO,ARQUIVADO'], 'movimentacao_id' => ['nullable', 'integer'],
        ]);
        $q = Documento::query()->with(['tipo:id,codigo,nome', 'unidade:id,sigla', 'enviadoPor:id,nome']);
        $this->authz()->escopar($q->getQuery(), $r->user(), 'documentos.ver');
        if (! empty($f['q'])) {
            $q->where(fn ($w) => $w->where('titulo', 'like', $this->like($f['q']))->orWhere('nome_original', 'like', $this->like($f['q']))
                ->orWhere('numero_referencia', 'like', $this->like($f['q'])));
        }
        foreach (['tipo_documento_id', 'unidade_id', 'situacao'] as $k) {
            if (isset($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['movimentacao_id'])) {
            $q->whereHas('vinculos', fn ($w) => $w->where('movimentacao_id', $f['movimentacao_id']));
        }

        return $this->paginar($r, $q, fn (Documento $d) => $this->resumo($d), ['id', 'titulo', 'created_at'], '-id');
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
        ];
    }

    private function detalhe(Request $r, Documento $d): array
    {
        $d->loadMissing(['tipo:id,codigo,nome', 'unidade:id,sigla,nome', 'enviadoPor:id,nome', 'vinculos.movimentacao']);
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
        ];
    }
}
