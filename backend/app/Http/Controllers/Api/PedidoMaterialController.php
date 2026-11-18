<?php

namespace App\Http\Controllers\Api;

use App\Models\LocalEstoque;
use App\Models\Militar;
use App\Models\PedidoMaterial;
use App\Models\PedidoMaterialItem;
use App\Models\Usuario;
use App\Services\PedidoMaterialService;
use App\Support\Natureza;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal do efetivo — lado do armeiro (permissão `pedidos.gerir`, no escopo da unidade do efetivo): painel de pedidos,
 * aprovação antecipada (com redução de quantidades) ou recusa (motivo obrigatório) e entrega presencial com conferência.
 * Rotas por código ULID; fora do escopo = 404 uniforme.
 */
class PedidoMaterialController extends ApiController
{
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function __construct(private PedidoMaterialService $svc) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('pedidos.gerir');
        $f = $r->validate(['status' => ['nullable', 'in:'.implode(',', PedidoMaterial::STATUS)], 'q' => ['nullable', 'string', 'max:80'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'], 'militar_id' => ['nullable', 'integer']]);
        $q = PedidoMaterial::query()->with(self::RELACOES);
        $this->authz()->escopar($q, $r->user(), 'pedidos.gerir');
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (! empty($f['militar_id'])) {
            $q->where('militar_id', $f['militar_id']);
        }
        if (! empty($f['de'])) {
            $q->where('created_at', '>=', $f['de'].' 00:00:00');
        }
        if (! empty($f['ate'])) {
            $q->where('created_at', '<=', $f['ate'].' 23:59:59');
        }
        if (! empty($f['q'])) {
            $t = trim($f['q']);
            // Busca pelo código do comprovante (inteiro ou os 6 últimos, "PED-XXXXXX") ou pelo efetivo (nome, nome de guerra, matrícula).
            $cod = strtoupper(preg_replace('/^PED-/i', '', $t));
            $q->where(fn ($w) => $w->where('codigo', $cod)->orWhere('codigo', 'like', '%'.addcslashes($cod, '%_\\'))
                ->orWhereHas('militar', fn ($m) => $m->where('nome', 'like', $this->like($t))->orWhere('nome_guerra', 'like', $this->like($t))->orWhere('matricula', $t)));
        }
        $u = $r->user();

        return $this->paginar($r, $q, fn (PedidoMaterial $p) => self::resumo($p) + ['acoes' => $this->acoes($u, $p)], ['id', 'retirada_prevista'], '-id');
    }

    /** Contadores do menu (pendentes de decisão e aprovados aguardando retirada), no escopo. */
    public function resumoContadores(Request $r): JsonResponse
    {
        $this->exigir('pedidos.gerir');
        $base = fn () => $this->authz()->escopar(PedidoMaterial::query(), $r->user(), 'pedidos.gerir');

        return response()->json(['pendentes' => $base()->where('status', 'PENDENTE')->count(), 'aprovados' => $base()->where('status', 'APROVADO')->count()]);
    }

    public function show(Request $r, string $codigo): JsonResponse
    {
        $p = $this->obter($r, $codigo);
        $u = $r->user();

        return response()->json(self::detalhe($p, $u) + ['efetivo_conferencia' => $this->conferencia($u, Militar::findOrFail($p->militar_id)), 'acoes' => $this->acoes($u, $p)]);
    }

    public function decidir(Request $r, string $codigo): JsonResponse
    {
        $p = $this->obter($r, $codigo);
        $d = $r->validate([
            'decisao' => ['required', 'in:APROVAR,RECUSAR'], 'versao' => ['required', 'integer'],
            'motivo' => ['nullable', 'string', 'max:500'], 'observacao' => ['nullable', 'string', 'max:500'],
            'itens' => ['nullable', 'array', 'max:50'], 'itens.*.id' => ['required', 'integer', 'distinct'],
            'itens.*.quantidade_aprovada' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $this->exigir('pedidos.gerir', $p->unidade_id);
        $qtd = collect($d['itens'] ?? [])->mapWithKeys(fn ($i) => [(int) $i['id'] => (int) $i['quantidade_aprovada']])->all();
        $p = $this->svc->decidir($r->user(), $p, $d['decisao'] === 'APROVAR', ['quantidades' => $qtd, 'observacao' => $d['observacao'] ?? null, 'motivo' => $d['motivo'] ?? null], $d['versao']);

        return $this->show($r, $p->codigo);
    }

    public function entregar(Request $r, string $codigo): JsonResponse
    {
        $p = $this->obter($r, $codigo);
        $chave = (string) $r->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9-]{16,80}$/', $chave)) {
            throw new SigarbException('IDEMPOTENCY_KEY_OBRIGATORIA', 'Cabeçalho Idempotency-Key obrigatório (16–80 caracteres alfanuméricos ou hífen).', 428);
        }
        $max = config('sigarb.movimentacao.max_itens');
        $d = $r->validate([
            'versao' => ['required', 'integer'], 'local_id' => ['required', 'integer', 'exists:locais_estoque,id'],
            'previsao_retorno' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'linhas' => ['required', 'array', 'min:1', 'max:50'], 'linhas.*.id' => ['required', 'integer'],
            'linhas.*.itens' => ['nullable', 'array', "max:$max"], 'linhas.*.itens.*' => ['integer'],
            'linhas.*.lotes' => ['nullable', 'array', "max:$max"], 'linhas.*.lotes.*.lote_id' => ['required', 'integer'],
            'linhas.*.lotes.*.quantidade' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $unidadeLocal = LocalEstoque::whereKey($d['local_id'])->value('unidade_id');
        $this->exigir('pedidos.gerir', $p->unidade_id);
        $this->exigir('estoque.entregar', $unidadeLocal);
        $temArma = PedidoMaterialItem::where('pedido_id', $p->id)->whereHas('material.tipo', fn ($t) => $t->where('perfil', PedidoMaterialService::PERFIL_ACAUTELAMENTO))
            ->whereIn('id', collect($d['linhas'])->filter(fn ($l) => ! empty($l['itens']))->pluck('id'))->exists();
        if ($temArma) {
            $this->exigir('acautelamento.operar', $unidadeLocal);
        }
        [$p, $replay] = $this->svc->entregar($r->user(), $p, $d, $d['versao'], $chave);

        return $this->show($r, $p->codigo)->setStatusCode($replay ? 200 : 201)->header('Idempotent-Replayed', $replay ? 'true' : 'false');
    }

    public const RELACOES = ['militar:id,nome,nome_guerra,matricula,posto_graduacao_id,unidade_id,categoria_efetivo', 'militar.postoGraduacao:id,sigla',
        'unidade:id,sigla', 'itens.material:id,codigo,nome,modo_controle,unidade_medida,categoria_id,tipo_id', 'itens.material.categoria:id,nome,natureza',
        'itens.material.tipo:id,nome,perfil', 'localRetirada:id,codigo,nome'];

    public static function resumo(PedidoMaterial $p): array
    {
        $p->loadMissing(self::RELACOES);

        return [
            'codigo' => $p->codigo, 'rotulo' => PedidoMaterialService::rotulo($p), 'status' => $p->status, 'versao' => $p->versao,
            'efetivo' => ['id' => $p->militar->id, 'nome_guerra' => $p->militar->nome_guerra, 'posto' => $p->militar->sigla_exibicao,
                'categoria_efetivo' => $p->militar->categoria_efetivo, 'apto_arma_fogo' => in_array($p->militar->categoria_efetivo, Militar::APTAS_LETAL, true)],
            'unidade' => $p->unidade->only(['id', 'sigla']),
            'retirada_prevista' => $p->retirada_prevista?->format('Y-m-d'),
            'local_retirada' => $p->localRetirada?->only(['id', 'codigo', 'nome']),
            'itens_resumo' => $p->itens->map(fn ($i) => $i->material->nome.' × '.($i->quantidade_aprovada ?? $i->quantidade))->implode(', '),
            'tem_letal' => $p->itens->contains(fn ($i) => in_array($i->material->categoria?->natureza, Militar::NATUREZAS_LETAIS, true)),
            'criado_em' => $p->created_at->toIso8601String(), 'decidido_em' => $p->decidido_em?->toIso8601String(), 'entregue_em' => $p->entregue_em?->toIso8601String(),
        ];
    }

    /** Detalhe comum ao armeiro e ao próprio efetivo (sem dados pessoais além do nome de quem decidiu/entregou). */
    public static function detalhe(PedidoMaterial $p, Usuario $u): array
    {
        $p->loadMissing(['decisor:id,nome', 'entregador:id,nome', 'acautelamentos:id,codigo,estado,item_individual_id', 'acautelamentos.item:id,identificador,numero_serie']);

        return self::resumo($p) + [
            'observacao' => $p->observacao, 'motivo_recusa' => $p->motivo_recusa, 'observacao_armeiro' => $p->observacao_armeiro,
            'decidido_por' => $p->decisor?->nome, 'entregue_por' => $p->entregador?->nome, 'cancelado_em' => $p->cancelado_em?->toIso8601String(),
            'entrega_movimentacao_id' => $p->entrega_movimentacao_id,
            'acautelamentos' => $p->acautelamentos->map(fn ($a) => ['codigo' => $a->codigo, 'estado' => $a->estado, 'item' => $a->item?->numero_serie ?: $a->item?->identificador])->values(),
            'itens' => $p->itens->map(fn ($i) => [
                'id' => $i->id, 'quantidade' => (int) $i->quantidade, 'quantidade_aprovada' => $i->quantidade_aprovada, 'quantidade_entregue' => $i->quantidade_entregue,
                'material' => ['id' => $i->material->id, 'codigo' => $i->material->codigo, 'nome' => $i->material->nome, 'modo_controle' => $i->material->modo_controle,
                    'unidade_medida' => $i->material->unidade_medida, 'categoria' => $i->material->categoria?->nome, 'natureza' => $i->material->categoria?->natureza,
                    'natureza_rotulo' => Natureza::rotulo($i->material->categoria?->natureza), 'perfil' => $i->material->tipo?->perfil,
                    'acautelamento' => $i->material->tipo?->perfil === PedidoMaterialService::PERFIL_ACAUTELAMENTO],
            ])->values(),
        ];
    }

    /** Conferência de identidade na entrega: foto, nome, posto, matrícula, CPF mascarado e aptidão. */
    private function conferencia(Usuario $u, Militar $m): array
    {
        $m->loadMissing('postoGraduacao', 'unidade');
        $cpf = $m->cpf ? '•••.'.substr($m->cpf, 3, 3).'.'.substr($m->cpf, 6, 3).'-••' : null;

        return [
            'id' => $m->id, 'nome' => $m->nome, 'nome_guerra' => $m->nome_guerra, 'posto' => $m->sigla_exibicao, 'unidade' => $m->unidade?->sigla,
            'matricula' => $this->authz()->pode($u, 'militares.ver_detalhe', $m->unidade_id) ? $m->matricula : '•••'.substr((string) $m->matricula, -3),
            'cpf_mascarado' => $cpf, 'situacao' => $m->situacao, 'categoria_efetivo' => $m->categoria_efetivo,
            'apto_arma_fogo' => in_array($m->categoria_efetivo, Militar::APTAS_LETAL, true),
            'foto_versao' => $m->foto_caminho && $this->authz()->podeAlguma($u, ['militares.ver', 'pedidos.gerir'], $m->unidade_id) ? $m->foto_atualizada_em?->timestamp : null,
        ];
    }

    private function acoes(Usuario $u, PedidoMaterial $p): array
    {
        $gere = $this->authz()->pode($u, 'pedidos.gerir', $p->unidade_id);

        return [
            'decidir' => $gere && $p->status === 'PENDENTE',
            'entregar' => $gere && $p->status === 'APROVADO' && Operacoes::estado('ENTREGA') !== 'BLOQUEADA' && $this->authz()->pode($u, 'estoque.entregar'),
        ];
    }

    private function obter(Request $r, string $codigo): PedidoMaterial
    {
        $p = preg_match(self::ULID, $codigo) ? PedidoMaterial::where('codigo', $codigo)->first() : null;
        if (! $p || ! $this->authz()->pode($r->user(), 'pedidos.gerir', $p->unidade_id)) {
            throw new SigarbException('NAO_ENCONTRADO', 'Pedido não encontrado.', 404);
        }

        return $p;
    }
}
