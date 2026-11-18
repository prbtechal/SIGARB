<?php

namespace App\Services;

use App\Models\Acautelamento;
use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Lote;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Notificacao;
use App\Models\PedidoMaterial;
use App\Models\PedidoMaterialItem;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\SigarbException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portal do efetivo (decisão do responsável, 2026-11-18): pedido prévio de material, aprovação antecipada e entrega presencial.
 * PENDENTE → (APROVADO | RECUSADO | CANCELADO); APROVADO → (ENTREGUE | CANCELADO).
 * Toda transição trava a linha do pedido (FOR UPDATE) e confere a versão enviada pelo cliente (409 se mudou).
 * A entrega NÃO grava estoque aqui: reaproveita a ENTREGA (OperacaoEstoqueService) e, para arma de fogo, o ACAUTELAMENTO
 * (AcautelamentoService: solicitação → decisão → entrega com carteira). Material letal só para Militar::APTAS_LETAL.
 */
class PedidoMaterialService
{
    /** Perfil do tipo de material entregue por acautelamento (cautela individual com carteira e inspeção). */
    public const PERFIL_ACAUTELAMENTO = 'ARMA_FOGO';

    public function __construct(private AuditoriaService $auditoria, private AutorizacaoService $authz) {}

    public static function limiteAbertos(): int
    {
        return max(1, (int) config('sigarb.pedidos.limite_abertos', 3));
    }

    /** Motivo do bloqueio de um material para o efetivo (null = pode pedir). */
    public static function bloqueio(Militar $m, ?string $natureza): ?string
    {
        if (! in_array($natureza, Militar::NATUREZAS_LETAIS, true) || in_array($m->categoria_efetivo, Militar::APTAS_LETAL, true)) {
            return null;
        }

        return $m->categoria_efetivo
            ? 'Arma de fogo e munição são só para RR (PM/BM) e PM/BM da ativa. Seu tipo de efetivo: '.(Militar::CATEGORIAS[$m->categoria_efetivo] ?? $m->categoria_efetivo).'.'
            : 'Seu cadastro não tem o tipo de efetivo (RR, PM/BM ativo, R1 ou Vigilante). Procure a T.I. para regularizar antes de pedir arma de fogo ou munição.';
    }

    /** Locais onde o efetivo pode indicar a retirada: locais ativos da unidade dele e das unidades acima. */
    public static function locaisRetirada(Militar $m): Collection
    {
        $caminho = Unidade::whereKey($m->unidade_id)->value('caminho');
        $ids = Unidade::all(['id', 'caminho'])->filter(fn ($u) => $caminho !== null && str_starts_with($caminho, $u->caminho))->pluck('id');

        // Depósito é da gestão (retomada, conferência e distribuição): nunca é local de retirada do efetivo (2026-11-18).
        return LocalEstoque::with('unidade:id,sigla')->where('ativo', true)->where('tipo', '!=', 'DEPOSITO')
            ->whereIn('unidade_id', $ids)->orderBy('codigo')->get();
    }

    /**
     * @param  array{itens: list<array{material_id: int, quantidade: int}>, retirada_prevista?: ?string, local_retirada_id?: ?int, observacao?: ?string}  $d
     */
    public function criar(Usuario $u, Militar $m, array $d): PedidoMaterial
    {
        if ($m->situacao !== 'ATIVO') {
            throw new SigarbException('EFETIVO_INATIVO', 'Seu cadastro de efetivo não está ativo. Procure a T.I.', 422);
        }
        $materiais = Material::with(['categoria', 'tipo'])->whereIn('id', array_column($d['itens'], 'material_id'))->get()->keyBy('id');
        foreach ($d['itens'] as $n => $i) {
            $mat = $materiais[$i['material_id']] ?? null;
            if (! $mat || ! $mat->ativo) {
                throw new SigarbException('MATERIAL_INDISPONIVEL', 'Um dos materiais não está mais disponível no catálogo.', 422, ['linha' => $n]);
            }
            if ($mat->modo_controle === Material::INDIVIDUAL && (int) $i['quantidade'] !== 1) {
                throw new SigarbException('QUANTIDADE_INVALIDA', "{$mat->nome} é controlado por unidade (nº de série): peça 1.", 422, ['linha' => $n]);
            }
            $this->exigirAptidao($m, $mat);
        }
        if (! empty($d['local_retirada_id']) && ! self::locaisRetirada($m)->contains('id', (int) $d['local_retirada_id'])) {
            throw new SigarbException('LOCAL_INVALIDO', 'Escolha um local de retirada da sua unidade.', 422);
        }

        $p = DB::transaction(function () use ($u, $m, $d, $materiais) {
            // Trava o efetivo: dois pedidos simultâneos não furam o limite de pedidos abertos.
            Militar::whereKey($m->id)->lockForUpdate()->first();
            $abertos = PedidoMaterial::where('militar_id', $m->id)->whereIn('status', PedidoMaterial::ABERTOS)->count();
            if ($abertos >= self::limiteAbertos()) {
                throw new SigarbException('LIMITE_PEDIDOS', 'Você já tem '.$abertos.' pedidos em aberto (limite: '.self::limiteAbertos().'). Aguarde a entrega ou cancele um deles.', 422);
            }
            $p = PedidoMaterial::create([
                'codigo' => (string) Str::ulid(), 'militar_id' => $m->id, 'unidade_id' => $m->unidade_id, 'solicitante_id' => $u->id, 'status' => 'PENDENTE',
                'retirada_prevista' => $d['retirada_prevista'] ?? null, 'local_retirada_id' => $d['local_retirada_id'] ?? null,
                'observacao' => isset($d['observacao']) ? trim($d['observacao']) ?: null : null,
            ]);
            foreach ($d['itens'] as $i) {
                PedidoMaterialItem::create(['pedido_id' => $p->id, 'material_id' => $i['material_id'], 'quantidade' => (int) $i['quantidade']]);
            }
            $this->auditoria->registrar('pedido.criado', 'SUCESSO', 'pedido_material', $p->id, $p->unidade_id, $this->resumoAuditoria($p, $materiais));

            return $p;
        });
        $this->notificarGestores($p, 'PEDIDO_NOVO', 'Novo pedido de material '.self::rotulo($p).' aguardando aprovação.');

        return $p;
    }

    /** Só o próprio efetivo cancela, enquanto PENDENTE ou APROVADO (ainda não entregue). */
    public function cancelar(Usuario $u, PedidoMaterial $p, ?string $motivo, int $versao): PedidoMaterial
    {
        return $this->transicao($p, $versao, ['PENDENTE', 'APROVADO'], function (PedidoMaterial $p) use ($u, $motivo) {
            if ((int) $p->militar_id !== (int) $u->militar_id) {
                throw new SigarbException('NAO_ENCONTRADO', 'Pedido não encontrado.', 404);
            }
            $p->fill(['status' => 'CANCELADO', 'cancelado_em' => now()]);
            $this->auditoria->registrar('pedido.cancelado', 'SUCESSO', 'pedido_material', $p->id, $p->unidade_id,
                ['codigo' => $p->codigo, 'motivo' => $motivo ? trim($motivo) : null]);
        });
    }

    /**
     * Aprovar (com ajuste das quantidades para baixo e observação) ou recusar (motivo obrigatório).
     *
     * @param  array{quantidades?: array<int, int>, observacao?: ?string, motivo?: ?string}  $d  quantidades: id da linha => quantidade aprovada
     */
    public function decidir(Usuario $u, PedidoMaterial $p, bool $aprovar, array $d, int $versao): PedidoMaterial
    {
        $p = $this->transicao($p, $versao, ['PENDENTE'], function (PedidoMaterial $p) use ($u, $aprovar, $d) {
            if ((int) $p->solicitante_id === (int) $u->id
                && ! $this->authz->temPapel($u, config('sigarb.politicas.autoaprovacao_papeis'), $p->unidade_id)) {
                throw new SigarbException('SEGREGACAO_FUNCOES', 'Quem fez o pedido não pode decidi-lo.', 403);
            }
            $obs = isset($d['observacao']) ? trim((string) $d['observacao']) ?: null : null;
            if (! $aprovar) {
                $motivo = trim((string) ($d['motivo'] ?? ''));
                if (mb_strlen($motivo) < 5) {
                    throw new SigarbException('MOTIVO_OBRIGATORIO', 'Informe o motivo da recusa (mínimo de 5 caracteres).', 422);
                }
                $p->fill(['status' => 'RECUSADO', 'decidido_por_id' => $u->id, 'decidido_em' => now(), 'motivo_recusa' => $motivo, 'observacao_armeiro' => $obs]);
                $this->auditoria->registrar('pedido.recusado', 'SUCESSO', 'pedido_material', $p->id, $p->unidade_id,
                    ['codigo' => $p->codigo, 'motivo' => $motivo, 'observacao' => $obs]);

                return;
            }
            $linhas = $p->itens()->with('material.categoria', 'material.tipo')->get();
            $militar = Militar::findOrFail($p->militar_id);
            $qtd = $d['quantidades'] ?? [];
            if (array_diff(array_keys($qtd), $linhas->pluck('id')->all())) {
                throw new SigarbException('LINHA_INVALIDA', 'Há uma linha que não pertence a este pedido.', 422);
            }
            $total = 0;
            $ajustes = [];
            foreach ($linhas as $l) {
                $aprovada = array_key_exists($l->id, $qtd) ? (int) $qtd[$l->id] : (int) $l->quantidade;
                if ($aprovada < 0 || $aprovada > $l->quantidade) {
                    throw new SigarbException('QUANTIDADE_INVALIDA', "A quantidade aprovada de {$l->material->nome} deve ficar entre 0 e {$l->quantidade}.", 422);
                }
                if ($aprovada > 0) {
                    // O tipo de efetivo pode ter mudado desde o pedido: o servidor revalida.
                    $this->exigirAptidao($militar, $l->material);
                    if ($l->material->tipo?->perfil === self::PERFIL_ACAUTELAMENTO && ! $this->authz->pode($u, 'acautelamento.aprovar', $p->unidade_id)) {
                        throw new SigarbException('ACESSO_NEGADO', 'Arma de fogo é entregue por acautelamento: aprovar exige a permissão de decidir acautelamentos.', 403);
                    }
                }
                $l->update(['quantidade_aprovada' => $aprovada]);
                $total += $aprovada;
                if ($aprovada !== (int) $l->quantidade) {
                    $ajustes[] = ['material' => $l->material->nome, 'pedida' => (int) $l->quantidade, 'aprovada' => $aprovada];
                }
            }
            if ($total === 0) {
                throw new SigarbException('NADA_APROVADO', 'Para aprovar, deixe ao menos uma linha com quantidade. Para negar tudo, use Recusar.', 422);
            }
            $p->fill(['status' => 'APROVADO', 'decidido_por_id' => $u->id, 'decidido_em' => now(), 'observacao_armeiro' => $obs]);
            $this->auditoria->registrar('pedido.aprovado', 'SUCESSO', 'pedido_material', $p->id, $p->unidade_id,
                ['codigo' => $p->codigo, 'reducoes' => $ajustes, 'observacao' => $obs]);
        });
        $this->notificar($p->solicitante_id, $p, $aprovar ? 'PEDIDO_APROVADO' : 'PEDIDO_RECUSADO',
            $aprovar ? 'Seu pedido '.self::rotulo($p).' foi aprovado. Retire com o armeiro levando um documento.' : 'Seu pedido '.self::rotulo($p).' foi recusado. Veja o motivo.');

        return $p;
    }

    /**
     * Entrega presencial do pedido APROVADO, depois da conferência de identidade pelo armeiro.
     * Arma de fogo (perfil ARMA_FOGO): um acautelamento por item (solicitado pelo efetivo, decidido por quem aprovou o pedido,
     * entregue por quem está entregando) — mesma trilha, carteira e inspeção do fluxo de acautelamento.
     * Demais materiais: uma movimentação ENTREGA com todos os itens e lotes (previsão de retorno = pendente; sem data = consumo).
     * Entrega única: o que não foi entregue fica registrado (quantidade_entregue < aprovada); o pedido vira ENTREGUE.
     * Idempotente: mesma Idempotency-Key devolve o mesmo resultado; a ENTREGA usa a chave derivada do código (uma por pedido).
     *
     * @param  array{local_id: int, previsao_retorno?: ?string, linhas: list<array{id: int, itens?: list<int>, lotes?: list<array{lote_id: int, quantidade: int}>}>}  $d
     * @return array{0: PedidoMaterial, 1: bool}
     */
    public function entregar(Usuario $u, PedidoMaterial $p, array $d, int $versao, string $chave): array
    {
        $replay = false;
        $p = DB::transaction(function () use ($u, $p, $d, $versao, $chave, &$replay) {
            $atual = PedidoMaterial::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($atual->status === 'ENTREGUE') {
                if ($atual->entrega_chave !== null && hash_equals($atual->entrega_chave, $chave)) {
                    $replay = true;

                    return $atual;
                }
                throw new SigarbException('JA_ENTREGUE', 'Este pedido já foi entregue.', 409);
            }
            if ($atual->versao !== $versao) {
                throw new SigarbException('VERSAO_DESATUALIZADA', 'O pedido foi alterado por outra pessoa. Recarregue e tente de novo.', 409);
            }
            if ($atual->status !== 'APROVADO') {
                throw new SigarbException('TRANSICAO_INVALIDA', "Só pedido aprovado pode ser entregue (situação: {$atual->status}).", 409);
            }
            $local = LocalEstoque::findOrFail($d['local_id']);
            if (! $local->ativo) {
                throw new SigarbException('LOCAL_INATIVO', 'O local de origem está inativo.', 422);
            }
            $militar = Militar::findOrFail($atual->militar_id);
            if ($militar->situacao !== 'ATIVO') {
                throw new SigarbException('EFETIVO_INATIVO', 'O efetivo não está ativo e não pode receber material.', 422);
            }
            $linhas = $atual->itens()->with('material.categoria', 'material.tipo')->get()->keyBy('id');
            $porLinha = [];
            foreach ($d['linhas'] as $e) {
                $l = $linhas[(int) $e['id']] ?? throw new SigarbException('LINHA_INVALIDA', 'Há uma linha que não pertence a este pedido.', 422);
                if (isset($porLinha[$l->id])) {
                    throw new SigarbException('LINHA_INVALIDA', 'Linha repetida na entrega.', 422);
                }
                $itens = array_values(array_unique(array_map('intval', $e['itens'] ?? [])));
                $lotes = $e['lotes'] ?? [];
                $qtd = count($itens) + array_sum(array_map(fn ($x) => (int) $x['quantidade'], $lotes));
                if ($qtd === 0) {
                    continue;
                }
                if ($qtd > (int) $l->quantidade_aprovada) {
                    throw new SigarbException('ACIMA_DO_APROVADO', "{$l->material->nome}: aprovado {$l->quantidade_aprovada}, informado $qtd.", 422);
                }
                if ($itens && ItemIndividual::whereIn('id', $itens)->where('material_id', '!=', $l->material_id)->exists()
                    || count($itens) !== ItemIndividual::whereIn('id', $itens)->count()) {
                    throw new SigarbException('ITEM_INVALIDO', "Há item que não é {$l->material->nome}.", 422);
                }
                $loteIds = array_map(fn ($x) => (int) $x['lote_id'], $lotes);
                if ($lotes && (count(array_unique($loteIds)) !== count($loteIds) || Lote::whereIn('id', $loteIds)->where('material_id', $l->material_id)->count() !== count($loteIds))) {
                    throw new SigarbException('ITEM_INVALIDO', "Há lote que não é de {$l->material->nome}.", 422);
                }
                $this->exigirAptidao($militar, $l->material);
                $porLinha[$l->id] = ['linha' => $l, 'itens' => $itens, 'lotes' => $lotes, 'qtd' => $qtd];
            }
            if (! $porLinha) {
                throw new SigarbException('NADA_A_ENTREGAR', 'Escolha ao menos um item ou lote para entregar.', 422);
            }

            $acautelamentos = [];
            $entrega = ['itens' => []];
            foreach ($porLinha as $x) {
                if ($x['linha']->material->tipo?->perfil === self::PERFIL_ACAUTELAMENTO) {
                    if ($x['lotes']) {
                        throw new SigarbException('ITEM_INVALIDO', 'Arma de fogo é entregue por número de série, não por lote.', 422);
                    }
                    foreach ($x['itens'] as $id) {
                        if (ItemIndividual::whereKey($id)->value('local_estoque_id') !== $local->id) {
                            throw new SigarbException('ITEM_INDISPONIVEL', 'A arma escolhida não está no local de origem.', 409);
                        }
                        $acautelamentos[] = $this->acautelar($u, $atual, $militar, $id);
                    }

                    continue;
                }
                foreach ($x['itens'] as $id) {
                    $entrega['itens'][] = ['item_individual_id' => $id];
                }
                foreach ($x['lotes'] as $lt) {
                    $entrega['itens'][] = ['lote_id' => (int) $lt['lote_id'], 'quantidade' => (int) $lt['quantidade']];
                }
            }
            $mov = null;
            if ($entrega['itens']) {
                [$mov] = app(OperacaoEstoqueService::class)->abrir($u, 'ENTREGA', [
                    'local_origem_id' => $local->id, 'militar_id' => $militar->id, 'previsao_retorno' => $d['previsao_retorno'] ?? null,
                    'documento_referencia' => 'PEDIDO-'.$atual->codigo, 'justificativa' => 'Entrega do pedido de material '.$atual->codigo,
                    'itens' => $entrega['itens'],
                ], 'pedido-'.$atual->codigo);
            }
            foreach ($linhas as $l) {
                $l->update(['quantidade_entregue' => $porLinha[$l->id]['qtd'] ?? 0]);
            }
            if ($acautelamentos) {
                $atual->acautelamentos()->attach(array_map(fn (Acautelamento $a) => $a->id, $acautelamentos));
            }
            $atual->fill(['status' => 'ENTREGUE', 'entregue_por_id' => $u->id, 'entregue_em' => now(), 'entrega_movimentacao_id' => $mov?->id, 'entrega_chave' => $chave]);
            $atual->versao++;
            $atual->save();
            $this->auditoria->registrar('pedido.entregue', 'SUCESSO', 'pedido_material', $atual->id, $atual->unidade_id, [
                'codigo' => $atual->codigo, 'local_id' => $local->id, 'movimentacao_id' => $mov?->id,
                'acautelamentos' => array_map(fn (Acautelamento $a) => $a->codigo, $acautelamentos),
                'linhas' => $linhas->map(fn ($l) => ['material' => $l->material->nome, 'aprovada' => (int) $l->quantidade_aprovada, 'entregue' => (int) $l->quantidade_entregue])->values()->all(),
                'parcial' => $linhas->contains(fn ($l) => (int) $l->quantidade_entregue < (int) $l->quantidade_aprovada),
                'previsao_retorno' => $d['previsao_retorno'] ?? null,
            ]);

            return $atual;
        });
        if (! $replay) {
            $this->notificar($p->solicitante_id, $p, 'PEDIDO_ENTREGUE', 'Material do pedido '.self::rotulo($p).' entregue. Confira em "Material comigo".');
        }

        return [$p, $replay];
    }

    /** Arma de fogo: acautelamento solicitado pelo efetivo (dono do pedido), decidido por quem aprovou o pedido e entregue agora. */
    private function acautelar(Usuario $u, PedidoMaterial $p, Militar $militar, int $itemId): Acautelamento
    {
        $svc = app(AcautelamentoService::class);
        $just = 'Pedido de material '.$p->codigo;
        $a = $svc->solicitar(Usuario::findOrFail($p->solicitante_id), ['militar_id' => $militar->id, 'item_individual_id' => $itemId, 'justificativa' => $just]);
        $a = $svc->decidir(Usuario::findOrFail($p->decidido_por_id), $a, true, 'Aprovado no '.$just, (int) $a->fresh()->versao);

        return $svc->entregar($u, $a, $a->versao);
    }

    private function transicao(PedidoMaterial $p, int $versao, array $de, callable $f): PedidoMaterial
    {
        return DB::transaction(function () use ($p, $versao, $de, $f) {
            $atual = PedidoMaterial::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($atual->versao !== $versao) {
                throw new SigarbException('VERSAO_DESATUALIZADA', 'O pedido foi alterado por outra pessoa. Recarregue e tente de novo.', 409);
            }
            if (! in_array($atual->status, $de, true)) {
                throw new SigarbException('TRANSICAO_INVALIDA', "Ação não permitida no pedido {$atual->status}.", 409);
            }
            $f($atual);
            $atual->versao++;
            $atual->save();

            return $atual;
        });
    }

    private function exigirAptidao(Militar $m, Material $mat): void
    {
        if (self::bloqueio($m, $mat->categoria?->natureza) !== null) {
            $m->exigirAptoLetal();
        }
    }

    private function resumoAuditoria(PedidoMaterial $p, Collection $materiais): array
    {
        $p->loadMissing('militar.postoGraduacao', 'itens');

        return [
            'codigo' => $p->codigo, 'status' => $p->status,
            'militar' => ['id' => $p->militar->id, 'matricula' => $p->militar->matricula, 'posto' => $p->militar->postoGraduacao?->sigla, 'nome_guerra' => $p->militar->nome_guerra],
            'itens' => $p->itens->map(fn ($i) => ['material' => $materiais[$i->material_id]?->nome, 'quantidade' => (int) $i->quantidade])->values()->all(),
            'retirada_prevista' => $p->retirada_prevista?->format('Y-m-d'), 'local_retirada_id' => $p->local_retirada_id,
        ];
    }

    public static function rotulo(PedidoMaterial $p): string
    {
        return 'PED-'.substr($p->codigo, -6);
    }

    private function notificar(?int $usuarioId, PedidoMaterial $p, string $tipo, string $texto): void
    {
        if ($usuarioId) {
            Notificacao::create(['usuario_id' => $usuarioId, 'tipo' => $tipo, 'pedido_material_id' => $p->id, 'texto' => Str::limit($texto, 200)]);
        }
    }

    /** Avisa quem tem `pedidos.gerir` na unidade do pedido (ou acima / global). Delegação vencida não conta. */
    private function notificarGestores(PedidoMaterial $p, string $tipo, string $texto): void
    {
        $caminho = Unidade::whereKey($p->unidade_id)->value('caminho');
        $ids = DB::table('atribuicoes_papel as a')
            ->join('papel_permissao as pp', 'pp.papel_id', '=', 'a.papel_id')->join('permissoes as pe', 'pe.id', '=', 'pp.permissao_id')
            ->join('usuarios as us', 'us.id', '=', 'a.usuario_id')->leftJoin('unidades as un', 'un.id', '=', 'a.unidade_id')
            ->where('pe.codigo', 'pedidos.gerir')->where('us.ativo', true)->where('a.usuario_id', '!=', $p->solicitante_id)
            ->where(fn ($q) => $q->whereNull('a.expira_em')->orWhere('a.expira_em', '>', now()))
            ->get(['a.usuario_id', 'a.unidade_id', 'un.caminho'])
            ->filter(fn ($l) => $l->unidade_id === null || ($caminho !== null && str_starts_with($caminho, $l->caminho)))
            ->pluck('usuario_id')->unique()->take(100);
        foreach ($ids as $id) {
            $this->notificar((int) $id, $p, $tipo, $texto);
        }
    }
}
