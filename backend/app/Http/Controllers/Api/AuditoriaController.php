<?php

namespace App\Http\Controllers\Api;

use App\Models\EventoAuditoria;
use App\Services\AuditoriaService;
use App\Services\RecursoAuditoriaService;
use App\Support\AcoesAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** RF-025, RF-027, UC-14. Somente leitura. */
class AuditoriaController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $this->exigir('auditoria.ver');
        $f = $r->validate([
            'acao' => ['nullable', 'string', 'max:80'], 'resultado' => ['nullable', 'in:SUCESSO,NEGADO,FALHA'],
            'usuario_id' => ['nullable', 'integer'], 'recurso_tipo' => ['nullable', 'string', 'max:60'], 'recurso_id' => ['nullable', 'integer'],
            'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date'], 'request_id' => ['nullable', 'string', 'max:64'],
        ]);
        $q = EventoAuditoria::query()->with('usuario:id,login,nome');
        $ids = $this->authz()->unidadesPermitidas($r->user(), 'auditoria.ver');
        if ($ids !== null) {
            $q->whereIn('unidade_id', $ids ?: [0]); // eventos sem unidade (ex.: autenticação) só para escopo global
        }
        if (! empty($f['acao'])) {
            $q->where('acao', 'like', addcslashes($f['acao'], '%_\\').'%');
        }
        foreach (['resultado', 'usuario_id', 'recurso_tipo', 'recurso_id', 'request_id'] as $k) {
            if (isset($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['de'])) {
            $q->where('ocorrido_em', '>=', $f['de']);
        }
        if (! empty($f['ate'])) {
            $q->where('ocorrido_em', '<', date('Y-m-d', strtotime($f['ate'].' +1 day')));
        }
        $this->auditoria()->registrar('auditoria.consulta', 'SUCESSO', dados: array_filter($f, fn ($v) => $v !== null));

        $recursos = [];
        $antes = function ($pagina) use (&$recursos, $r) {
            // A unidade do evento entra como mais um recurso "unidade" (mesma carga em lote e mesmo escopo).
            $unidades = $pagina->whereNotNull('unidade_id')->map(fn ($e) => (object) ['recurso_tipo' => 'unidade', 'recurso_id' => $e->unidade_id]);
            $recursos = app(RecursoAuditoriaService::class)->resolver($r->user(), $pagina->toBase()->merge($unidades));
        };

        return $this->paginar($r, $q, function (EventoAuditoria $e) use (&$recursos) {
            $rec = $e->recurso_tipo && $e->recurso_id ? $recursos["$e->recurso_tipo:$e->recurso_id"] : null;

            return [
                // Campos aditivos de apresentação (o código original continua em "acao").
                'acao_rotulo' => AcoesAuditoria::rotulo($e->acao),
                'recurso_rotulo' => $rec['rotulo'] ?? null, 'recurso_link' => $rec['link'] ?? null,
                'unidade_rotulo' => $e->unidade_id ? $recursos["unidade:$e->unidade_id"]['rotulo'] : null,
                'frase' => AcoesAuditoria::frase($e->acao, $e->usuario?->nome ?? 'Sistema', $rec['rotulo'] ?? null),
                'id' => $e->id, 'ocorrido_em' => $e->ocorrido_em->toIso8601String(), 'acao' => $e->acao, 'resultado' => $e->resultado,
                'usuario' => $e->usuario ? ['id' => $e->usuario->id, 'login' => $e->usuario->login, 'nome' => $e->usuario->nome] : null,
                'recurso_tipo' => $e->recurso_tipo, 'recurso_id' => $e->recurso_id, 'unidade_id' => $e->unidade_id,
                'ip' => $e->ip, 'request_id' => $e->request_id, 'dados' => $e->dados, 'hash' => substr($e->hash, 0, 12),
            ];
        }, ['id', 'ocorrido_em'], '-id', $antes);
    }

    /** Ações conhecidas com rótulo em português, para o filtro da tela. */
    public function acoes(): JsonResponse
    {
        $this->exigir('auditoria.ver');
        $lista = collect(AcoesAuditoria::todas())->map(fn ($a, $codigo) => ['codigo' => $codigo, 'rotulo' => $a[0]])->sortBy('rotulo')->values();

        return response()->json(['data' => $lista]);
    }

    public function integridade(Request $r, AuditoriaService $s): JsonResponse
    {
        Gate::allowIf(fn ($u) => $this->authz()->global($u, 'auditoria.ver'));
        $invalido = $s->verificar();

        return response()->json(['integra' => $invalido === null, 'primeiro_evento_invalido' => $invalido, 'total' => EventoAuditoria::count()]);
    }
}
