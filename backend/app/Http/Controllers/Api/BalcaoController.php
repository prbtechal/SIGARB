<?php

namespace App\Http\Controllers\Api;

use App\Models\Militar;
use App\Services\AutorizacaoService;
use App\Support\EntregaOpcoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Balcão do armeiro (decisão do responsável, 2026-11-18). O armeiro não tem a tela Efetivos (militares.ver), mas precisa
 * achar o efetivo para entregar, receber devolução de outra pessoa e pedir acautelamento. Busca SÓ no escopo das permissões
 * de operação (estoque.entregar, acautelamento.solicitar, pedidos.gerir) — a base do armeiro e as subunidades.
 */
class BalcaoController extends ApiController
{
    public const OPERAR = ['estoque.entregar', 'acautelamento.solicitar', 'pedidos.gerir'];

    /** GET /stock/recipients — efetivos ATIVOS do escopo por nome, nome de guerra, matrícula ou CPF (exatos; mesma regra de visibilidade). */
    public function efetivos(Request $r): JsonResponse
    {
        Gate::allowIf(fn ($u) => $this->authz()->podeAlguma($u, self::OPERAR));
        $f = $r->validate(['q' => ['required', 'string', 'min:2', 'max:80'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $u = $r->user();
        $t = trim($f['q']);
        $q = Militar::query()->with(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])->where('situacao', 'ATIVO');
        $this->authz()->escoparAlguma($q->getQuery(), $u, self::OPERAR);
        $detalhe = $this->authz()->unidadesPermitidas($u, 'militares.ver_detalhe');
        $digitos = preg_replace('/\D/', '', $t);
        $q->where(function ($w) use ($t, $digitos, $detalhe) {
            $w->where('nome', 'like', $this->like($t))->orWhere('nome_guerra', 'like', $this->like($t));
            // Matrícula/CPF exatos só onde a matrícula completa é visível (sem oráculo da máscara).
            $chave = fn ($m) => $m->where('matricula', $t)->when(strlen($digitos) === 11, fn ($c) => $c->orWhere('cpf', $digitos));
            if ($detalhe === null) {
                $w->orWhere($chave);
            } elseif ($detalhe !== []) {
                $w->orWhere(fn ($m) => $m->where($chave)->whereIn('unidade_id', $detalhe));
            }
        });
        $lista = $q->orderBy('nome_guerra')->orderBy('id')->limit((int) ($f['per_page'] ?? 10))->get();

        return response()->json(['data' => $lista->map(fn (Militar $m) => self::efetivo($r, $m))->values()]);
    }

    /** Resumo do efetivo para o balcão: sem contatos nem observações; matrícula/CPF completos só com militares.ver_detalhe. */
    public static function efetivo(Request $r, Militar $m): array
    {
        $authz = app(AutorizacaoService::class);
        $detalhe = $authz->pode($r->user(), 'militares.ver_detalhe', $m->unidade_id);

        return [
            'id' => $m->id, 'nome' => $m->nome, 'nome_guerra' => $m->nome_guerra,
            'matricula' => $detalhe ? $m->matricula : '•••'.substr((string) $m->matricula, -3), 'matricula_completa' => $detalhe,
            'cpf' => $m->cpf ? '•••.'.substr($m->cpf, 3, 3).'.'.substr($m->cpf, 6, 3).'-••' : null,
            'situacao' => $m->situacao, 'categoria_efetivo' => $m->categoria_efetivo,
            'apto_arma_fogo' => $m->categoria_efetivo === null ? null : in_array($m->categoria_efetivo, Militar::APTAS_LETAL, true),
            'posto_graduacao' => ['id' => $m->postoGraduacao->id, 'sigla' => $m->postoGraduacao->sigla],
            // Exibição (decisão 2026-11-18): CIV vira a sigla da categoria (RR, PM, BM, VIG, R1); sem categoria, só o nome.
            'sigla_exibicao' => $m->sigla_exibicao, 'rotulo_curto' => $m->rotulo_curto,
            'unidade' => ['id' => $m->unidade->id, 'sigla' => $m->unidade->sigla],
            'foto_versao' => $m->foto_caminho ? $m->foto_atualizada_em?->timestamp : null,
        ];
    }

    /** GET /stock/delivery-options — listas do formulário "Registrar entrega" e o local de origem padrão da base. */
    public function opcoesEntrega(Request $r): JsonResponse
    {
        $this->exigir('estoque.entregar');
        $u = $r->user();
        $ids = $this->authz()->unidadesPermitidas($u, 'estoque.entregar');
        $locais = DB::table('locais_estoque as l')->join('unidades as un', 'un.id', '=', 'l.unidade_id')->where('l.ativo', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('l.unidade_id', $ids ?: [0]));
        $padrao = null;
        if ($codigo = config('sigarb.entrega.local_padrao_codigo')) {
            $padrao = (clone $locais)->where('l.codigo', $codigo)->value('l.id');
        }
        // Primeiro local RESERVA da base: o da unidade mais alta do escopo (a base), depois o de menor id.
        $padrao ??= (clone $locais)->where('l.tipo', 'RESERVA')->orderByRaw('LENGTH(un.caminho)')->orderBy('l.id')->value('l.id');

        return response()->json([
            'documentos' => EntregaOpcoes::lista('documentos'),
            'motivos' => EntregaOpcoes::lista('motivos'),
            'local_origem_padrao_id' => $padrao,
        ]);
    }
}
