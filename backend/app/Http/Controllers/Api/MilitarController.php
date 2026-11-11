<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MilitarRequest;
use App\Models\Militar;
use App\Models\MilitarUnidade;
use App\Support\FotoSanitizada;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** RF-005, RF-026, §2.1. Matrícula mascarada sem `militares.ver_detalhe` (ADR-009). */
class MilitarController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Militar::class);
        $r->validate(['q' => ['nullable', 'string', 'max:80'], 'situacao' => ['nullable', 'in:ATIVO,AFASTADO,INATIVO'], 'unidade_id' => ['nullable', 'integer']]);
        $q = Militar::query()->with(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem']);
        $this->authz()->escopar($q->getQuery(), $r->user(), 'militares.ver');
        if ($t = $r->query('q')) {
            // Busca por matrícula só nas unidades em que a matrícula completa é visível (evita oráculo da máscara).
            $detalhe = $this->authz()->unidadesPermitidas($r->user(), 'militares.ver_detalhe');
            $q->where(function ($w) use ($t, $detalhe) {
                $w->where('nome', 'like', $this->like($t))->orWhere('nome_guerra', 'like', $this->like($t));
                if ($detalhe === null) {
                    $w->orWhere('matricula', $t);
                } elseif ($detalhe !== []) {
                    $w->orWhere(fn ($m) => $m->where('matricula', $t)->whereIn('unidade_id', $detalhe));
                }
            });
        }
        foreach (['situacao', 'unidade_id'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->query($f));
            }
        }

        return $this->paginar($r, $q, fn ($m) => $this->dto($r, $m), ['id', 'nome', 'nome_guerra'], 'nome');
    }

    public function show(Request $r, Militar $militar): JsonResponse
    {
        Gate::authorize('view', $militar);
        $militar->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem', 'usuario:id,login,militar_id']);
        $detalhe = $this->authz()->pode($r->user(), 'militares.ver_detalhe', $militar->unidade_id);
        if ($detalhe) {
            // Consulta sensível (RN-020; política provisória P-08).
            $this->auditoria()->registrar('militar.consulta_detalhe', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id);
        }
        $dto = $this->dto($r, $militar);
        if ($this->authz()->global($r->user(), 'usuarios.ver') || $detalhe) {
            $dto['usuario'] = $militar->usuario ? ['id' => $militar->usuario->id, 'login' => $militar->usuario->login] : null;
        }

        return response()->json($dto);
    }

    public function store(MilitarRequest $r): JsonResponse
    {
        $d = $r->validated();
        Gate::authorize('create', [Militar::class, (int) $d['unidade_id']]);
        $m = Militar::create($d);
        $this->auditoria()->registrar('militar.criado', 'SUCESSO', 'militar', $m->id, $m->unidade_id, ['matricula' => $m->matricula]);

        return response()->json($this->dto($r, $m->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])), 201);
    }

    public function update(MilitarRequest $r, Militar $militar): JsonResponse
    {
        $d = $r->validated();
        Gate::authorize('update', [$militar, isset($d['unidade_id']) ? (int) $d['unidade_id'] : null]);
        if (isset($d['unidade_id']) && (int) $d['unidade_id'] !== $militar->unidade_id
            && MilitarUnidade::where('militar_id', $militar->id)->where('unidade_id', $d['unidade_id'])->whereNull('fim')->exists()) {
            throw new SigarbException('UNIDADE_EM_VINCULO_SECUNDARIO', 'Encerre o vínculo secundário com esta unidade antes de torná-la principal.', 409);
        }
        $antes = $militar->only(array_keys($d));
        $militar->update($d);
        $this->auditoria()->registrar('militar.atualizado', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id,
            ['antes' => self::mascarar($antes), 'depois' => self::mascarar($d)]);

        return response()->json($this->dto($r, $militar->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])));
    }

    /** Contatos e observações não vão em claro para a auditoria (registro permanente): só se marcou a alteração. */
    private const PESSOAIS = ['telefone_institucional', 'email_institucional', 'observacoes_administrativas'];

    private static function mascarar(array $d): array
    {
        foreach (self::PESSOAIS as $c) {
            if (array_key_exists($c, $d)) {
                $d[$c] = $d[$c] === null || $d[$c] === '' ? null : '[informado]';
            }
        }

        return $d;
    }

    private function dto(Request $r, Militar $m): array
    {
        $detalhe = $this->authz()->pode($r->user(), 'militares.ver_detalhe', $m->unidade_id);

        return [
            'quadro' => $m->quadro, 'funcao' => $m->funcao,
            // Contatos e observações só com militares.ver_detalhe (mesma regra da matrícula completa).
            'telefone_institucional' => $detalhe ? $m->telefone_institucional : null,
            'email_institucional' => $detalhe ? $m->email_institucional : null,
            'observacoes_administrativas' => $detalhe ? $m->observacoes_administrativas : null,
            'id' => $m->id, 'nome' => $m->nome, 'nome_guerra' => $m->nome_guerra,
            'matricula' => $detalhe ? $m->matricula : '•••'.substr($m->matricula, -3),
            'matricula_completa' => $detalhe,
            'situacao' => $m->situacao, 'categoria_efetivo' => $m->categoria_efetivo,
            'posto_graduacao' => ['id' => $m->postoGraduacao->id, 'sigla' => $m->postoGraduacao->sigla],
            'unidade' => ['id' => $m->unidade->id, 'sigla' => $m->unidade->sigla],
            'pode_editar' => $this->authz()->pode($r->user(), 'militares.gerir', $m->unidade_id),
            // Versão da foto (cache-busting na URL); null = sem foto.
            'foto_versao' => $m->foto_caminho ? $m->foto_atualizada_em?->timestamp : null,
        ];
    }

    /** Tipo de efetivo: só com `militares.classificar` na unidade do militar (começa só no ADMIN), porque decide quem pode receber armamento. */
    public function categoria(Request $r, Militar $militar): JsonResponse
    {
        Gate::allowIf(fn ($u) => $this->authz()->pode($u, 'militares.classificar', $militar->unidade_id));
        $d = $r->validate([
            'categoria_efetivo' => ['present', 'nullable', \Illuminate\Validation\Rule::in(array_keys(Militar::CATEGORIAS))],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $antes = $militar->categoria_efetivo;
        $militar->update(['categoria_efetivo' => $d['categoria_efetivo']]);
        $this->auditoria()->registrar('militar.categoria_alterada', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id,
            ['antes' => $antes, 'depois' => $d['categoria_efetivo'], 'motivo' => $d['motivo']]);

        return response()->json($this->dto($r, $militar->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])));
    }

    /** Foto do perfil: só com sessão e escopo; resposta privada, sem cache compartilhado. */
    public function foto(Militar $militar): Response
    {
        Gate::authorize('view', $militar);
        if (! $militar->foto_caminho || ! Storage::disk('documentos')->exists($militar->foto_caminho)) {
            throw new SigarbException('NAO_ENCONTRADO', 'Foto não cadastrada.', 404);
        }
        $mime = str_ends_with($militar->foto_caminho, '.png') ? 'image/png' : 'image/jpeg';

        return response(Storage::disk('documentos')->get($militar->foto_caminho), 200, [
            'Content-Type' => $mime, 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="foto"',
        ]);
    }

    public function enviarFoto(Request $r, Militar $militar): JsonResponse
    {
        Gate::authorize('update', [$militar, null]);
        $r->validate(['foto' => ['required', 'file', 'max:'.(FotoSanitizada::MAX_BYTES / 1024)]]);
        $limpo = FotoSanitizada::limpar((string) file_get_contents($r->file('foto')->getRealPath()));
        if (! $limpo) {
            throw new SigarbException('FOTO_INVALIDA', 'Envie uma foto JPG ou PNG válida de até 3 MB.', 422);
        }
        [$bytes, $ext] = $limpo;
        $caminho = 'efetivos/'.Str::random(32).".$ext";
        Storage::disk('documentos')->put($caminho, $bytes);
        $antiga = $militar->foto_caminho;
        $militar->update(['foto_caminho' => $caminho, 'foto_atualizada_em' => now()]);
        if ($antiga) {
            Storage::disk('documentos')->delete($antiga);
        }
        $this->auditoria()->registrar('militar.foto_alterada', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id, ['bytes' => strlen($bytes), 'formato' => $ext]);

        return response()->json($this->dto($r, $militar->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])));
    }

    public function removerFoto(Request $r, Militar $militar): JsonResponse
    {
        Gate::authorize('update', [$militar, null]);
        if ($militar->foto_caminho) {
            Storage::disk('documentos')->delete($militar->foto_caminho);
            $militar->update(['foto_caminho' => null, 'foto_atualizada_em' => null]);
            $this->auditoria()->registrar('militar.foto_removida', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id);
        }

        return response()->json($this->dto($r, $militar->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])));
    }
}
