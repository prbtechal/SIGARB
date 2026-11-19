<?php

namespace App\Http\Controllers\Api;

use App\Support\CentralSuporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central de Suporte PRB (Central-Suporte/docs/INTEGRACAO.md §3): chamados e avisos do usuário logado, lidos das views
 * do banco `suporte`. Qualquer usuário autenticado vê só os PRÓPRIOS: o CPF é o da conta (usuarios.login), nunca um
 * parâmetro da requisição (todos são ignorados) e não volta na resposta. Desligada/indisponível = vazio (200).
 */
class CentralSuporteController extends ApiController
{
    // GET /api/v1/central-suporte
    public function __invoke(Request $r): JsonResponse
    {
        return response()->json(CentralSuporte::doUsuario($r->user()->login));
    }
}
