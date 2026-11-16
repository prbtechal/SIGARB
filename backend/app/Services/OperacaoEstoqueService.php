<?php

namespace App\Services;

use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Lote;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Movimentacao;
use App\Models\MovimentacaoItem;
use App\Models\Usuario;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operações com regra PROVISÓRIA (config sigarb.operacoes_provisorias): saída, baixa, transferência,
 * reserva e manutenção. Abertura recebe itens; conclusão (recebimento, liberação, retorno) copia os itens
 * da abertura e é única (movimentacoes.vinculo_id UNIQUE). Mesmo padrão de locks do EstoqueService:
 * itens por id, lotes por id, sempre dentro de uma transação.
 */
class OperacaoEstoqueService
{
    /** abertura => [situação exigida dos itens individuais, situação resultante, operação do portão] */
    public const ABERTURAS = [
        'SAIDA' => [['DISPONIVEL'], 'FORA_DO_ESTOQUE', 'SAIDA'],
        'BAIXA' => [['DISPONIVEL', 'MANUTENCAO'], 'BAIXADO', 'BAIXA'],
        'TRANSFERENCIA' => [['DISPONIVEL'], 'EM_TRANSITO', 'TRANSFERENCIA'],
        'RESERVA' => [['DISPONIVEL'], 'RESERVADO', 'RESERVA'],
        'MANUTENCAO' => [['DISPONIVEL'], 'MANUTENCAO', 'MANUTENCAO'],
        // RN-007: com previsão de retorno o item fica ENTREGUE (pendente de devolução); sem previsão sai do estoque.
        'ENTREGA' => [['DISPONIVEL'], 'ENTREGUE', 'ENTREGA'],
    ];

    /** conclusão => [abertura, situação exigida, situação resultante] */
    public const CONCLUSOES = [
        'RECEBIMENTO' => ['TRANSFERENCIA', 'EM_TRANSITO', 'DISPONIVEL'],
        'LIBERACAO_RESERVA' => ['RESERVA', 'RESERVADO', 'DISPONIVEL'],
        'RETORNO_MANUTENCAO' => ['MANUTENCAO', 'MANUTENCAO', 'DISPONIVEL'],
        'DEVOLUCAO' => ['ENTREGA', 'ENTREGUE', 'DISPONIVEL'], // RN-008: referencia a entrega de origem
    ];

    /** Aberturas que ficam pendentes até a conclusão (entrega só quando há previsão de retorno). */
    public const PENDENTES = ['TRANSFERENCIA', 'RESERVA', 'MANUTENCAO', 'ENTREGA'];

    public function __construct(private EstoqueService $estoque, private AuditoriaService $auditoria) {}

    /** @return array{0: Movimentacao, 1: bool} */
    public function abrir(Usuario $u, string $tipo, array $d, string $chave): array
    {
        [$exigida, $resultante, $op] = self::ABERTURAS[$tipo];
        Operacoes::exigir($op);
        if ($tipo === 'ENTREGA' && empty($d['previsao_retorno'])) {
            $resultante = 'FORA_DO_ESTOQUE';
        }
        $prov = Operacoes::estado($op) === 'PROVISORIA';
        $hash = $this->estoque->hashRequisicao($tipo, $d);
        if ($m = $this->estoque->replay($chave, $u, $hash)) {
            return [$m, true];
        }
        $origem = LocalEstoque::findOrFail($d['local_origem_id']);
        $destino = isset($d['local_destino_id']) ? LocalEstoque::findOrFail($d['local_destino_id']) : null;
        if ($tipo === 'TRANSFERENCIA') {
            if (! $destino || $destino->id === $origem->id) {
                throw new SigarbException('DESTINO_INVALIDO', 'Transferência exige destino diferente da origem.', 422);
            }
            if (! $destino->ativo) {
                throw new SigarbException('LOCAL_INATIVO', 'O local de destino está inativo.', 422);
            }
        }
        $individuais = collect($d['itens'])->pluck('item_individual_id')->filter()->map(fn ($v) => (int) $v)->sort()->values();
        $lotes = [];
        foreach ($d['itens'] as $i) {
            if (! empty($i['lote_id'])) {
                $lotes[(int) $i['lote_id']] = ($lotes[(int) $i['lote_id']] ?? 0) + (int) $i['quantidade'];
            }
        }
        if ($tipo === 'MANUTENCAO' && $lotes) {
            throw new SigarbException('ITEM_INVALIDO', 'Manutenção aceita somente itens individuais.', 422);
        }
        ksort($lotes);
        if ($tipo === 'ENTREGA' && ! empty($d['militar_id'])) {
            $materiais = ItemIndividual::whereIn('id', $individuais)->pluck('material_id')
                ->merge(Lote::whereIn('id', array_keys($lotes))->pluck('material_id'));
            $letal = Material::whereIn('id', $materiais)->whereHas('categoria', fn ($q) => $q->whereIn('natureza', Militar::NATUREZAS_LETAIS))->exists();
            if ($letal) {
                Militar::findOrFail($d['militar_id'])->exigirAptoLetal();
            }
        }

        try {
            $mov = DB::transaction(function () use ($u, $tipo, $d, $chave, $hash, $origem, $destino, $individuais, $lotes, $exigida, $resultante, $prov) {
                $mov = Movimentacao::create([
                    'tipo' => $tipo, 'local_origem_id' => $origem->id, 'local_destino_id' => $destino?->id,
                    'responsavel_id' => $u->id, 'ocorrido_em' => now(),
                    'origem_descricao' => $d['destinacao'] ?? null, 'documento_referencia' => $d['documento_referencia'] ?? null,
                    'justificativa' => $d['justificativa'], 'chave_idempotencia' => $chave, 'hash_requisicao' => $hash, 'provisoria' => $prov,
                    'militar_id' => $d['militar_id'] ?? null, 'previsao_retorno' => $d['previsao_retorno'] ?? null,
                ]);
                foreach ($individuais as $id) {
                    $item = ItemIndividual::whereKey($id)->lockForUpdate()->first();
                    if (! $item || $item->local_estoque_id !== $origem->id || ! in_array($item->situacao, $exigida, true)) {
                        throw new SigarbException('ITEM_INDISPONIVEL', 'Item '.($item->identificador ?? "#$id").' não está em situação válida no local de origem.', 409);
                    }
                    $this->exigirAptidao($tipo, $item->identificador, $item->validade, $item->estado_conservacao);
                    $item->update(['situacao' => $resultante]);
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'item_individual_id' => $item->id, 'quantidade' => 1]);
                }
                foreach ($lotes as $loteId => $qtd) {
                    if ($l = Lote::find($loteId)) {
                        $this->exigirAptidao($tipo, $l->codigo, $l->validade, null);
                    }
                    if ($tipo === 'RESERVA') {
                        $this->reservar($origem->id, $loteId, $qtd);
                    } else {
                        $this->estoque->debitarLote($origem->id, $loteId, $qtd);
                    }
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'lote_id' => $loteId, 'quantidade' => $qtd]);
                }
                $this->auditoria->registrar('estoque.'.strtolower($tipo), 'SUCESSO', 'movimentacao', $mov->id, $origem->unidade_id, [
                    'local_origem_id' => $origem->id, 'local_destino_id' => $destino?->id, 'itens' => count($d['itens']),
                    'documento_referencia' => $d['documento_referencia'] ?? null, 'regra' => $prov ? 'PROVISORIA' : 'DECISAO_RESPONSAVEL',
                ]);

                return $mov;
            }, 5);
        } catch (UniqueConstraintViolationException $e) {
            return $this->estoque->tratarUnicidade($e, $chave, $u, $hash);
        }

        return [$mov, false];
    }

    /**
     * @param  array<int, array{item_individual_id?: int, lote_id?: int, quantidade?: int, estado_conservacao?: string}>|null  $selecao
     *                                                                                                                                   Só na DEVOLUÇÃO (P-05, CP-13): itens/quantidades que voltam. Nulo = todo o restante.
     * @param  array{ocorrido_em?: ?string, devolvido_por_militar_id?: ?int, devolvido_por_nome?: ?string}  $extra  Só na devolução (decisão 2026-11-16)
     * @return array{0: Movimentacao, 1: bool}
     */
    public function concluir(Usuario $u, string $tipo, Movimentacao $abertura, ?string $justificativa, string $chave, ?array $selecao = null, array $extra = []): array
    {
        // $extra (devolução): data/hora real (retroativa exige justificativa) e quem devolveu (padrão: o efetivo da entrega; outro efetivo; ou terceiro).
        [$tipoAbertura, $exigida, $resultante] = self::CONCLUSOES[$tipo];
        $op = self::ABERTURAS[$tipoAbertura][2];
        Operacoes::exigir($op);
        $prov = Operacoes::estado($op) === 'PROVISORIA';
        $extra = array_filter($extra, fn ($v) => $v !== null && $v !== '');
        if ($extra && $tipo !== 'DEVOLUCAO') {
            throw new SigarbException('ITEM_INVALIDO', 'Data/hora e quem devolveu só se aplicam à devolução.', 422);
        }
        // Sem dados extras o hash é o mesmo de antes (chaves antigas continuam reconhecidas no replay).
        $hash = $this->estoque->hashRequisicao($tipo, ['vinculo_id' => $abertura->id, 'justificativa' => $justificativa, 'itens' => $selecao] + $extra);
        if ($m = $this->estoque->replay($chave, $u, $hash)) {
            return [$m, true];
        }
        if ($abertura->tipo !== $tipoAbertura) {
            throw new SigarbException('VINCULO_INVALIDO', "A movimentação vinculada não é do tipo $tipoAbertura.", 422);
        }
        if ($tipo === 'DEVOLUCAO' && ! $abertura->previsao_retorno) {
            throw new SigarbException('SEM_RETORNO', 'Entrega sem previsão de retorno não admite devolução.', 422);
        }
        [$ocorrido, $retroativa] = EstoqueService::ocorrido($extra['ocorrido_em'] ?? null, $justificativa, 'devolução');
        if ($ocorrido->lt($abertura->ocorrido_em)) {
            throw ValidationException::withMessages(['ocorrido_em' => ['A devolução não pode ser anterior à entrega.']]);
        }
        $devolvidoPor = null;
        if ($tipo === 'DEVOLUCAO') {
            // Quem trouxe o material: padrão = o próprio efetivo da entrega.
            $devolvidoPor = isset($extra['devolvido_por_nome']) ? null : (int) ($extra['devolvido_por_militar_id'] ?? $abertura->militar_id);
        }
        if ($selecao !== null && $tipo !== 'DEVOLUCAO') {
            throw new SigarbException('ITEM_INVALIDO', 'Só a devolução aceita seleção de itens.', 422);
        }
        $credita = in_array($tipo, ['RECEBIMENTO', 'DEVOLUCAO'], true);
        $local = $tipo === 'RECEBIMENTO' ? $abertura->local_destino_id : $abertura->local_origem_id;
        if ($tipo === 'RECEBIMENTO' && ! LocalEstoque::whereKey($local)->value('ativo')) {
            throw new SigarbException('LOCAL_INATIVO', 'O local de destino está inativo.', 422);
        }
        if ($credita) {
            foreach ($abertura->itens()->whereNotNull('lote_id')->pluck('lote_id')->unique() as $loteId) {
                $this->estoque->garantirSaldo($local, $loteId); // fora da transação (ver EstoqueService)
            }
        }

        try {
            $mov = DB::transaction(function () use ($u, $tipo, $abertura, $justificativa, $chave, $hash, $local, $exigida, $resultante, $credita, $selecao, $prov, $ocorrido, $retroativa, $devolvidoPor, $extra) {
                $ab = Movimentacao::whereKey($abertura->id)->lockForUpdate()->firstOrFail();
                if (Movimentacao::where('vinculo_id', $ab->id)->exists()) {
                    throw new SigarbException('JA_CONCLUIDA', 'Esta operação já foi concluída.', 409);
                }
                [$individuais, $porLote, $final] = $this->restante($ab, $selecao);
                $mov = Movimentacao::create([
                    'tipo' => $tipo, 'local_origem_id' => $ab->local_origem_id, 'local_destino_id' => $tipo === 'RECEBIMENTO' ? $local : null,
                    'responsavel_id' => $u->id, 'ocorrido_em' => $ocorrido, 'documento_referencia' => $ab->documento_referencia,
                    'justificativa' => $justificativa, 'chave_idempotencia' => $chave, 'hash_requisicao' => $hash,
                    'vinculo_id' => $final ? $ab->id : null, 'vinculo_parcial_id' => $final ? null : $ab->id,
                    'provisoria' => $prov, 'militar_id' => $ab->militar_id,
                    'devolvido_por_militar_id' => $devolvidoPor, 'devolvido_por_nome' => $tipo === 'DEVOLUCAO' ? ($extra['devolvido_por_nome'] ?? null) : null,
                ]);
                $conservacao = [];
                foreach ($individuais as $id => $estado) {
                    $item = ItemIndividual::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($item->situacao !== $exigida) {
                        throw new SigarbException('ITEM_INDISPONIVEL', "O item {$item->identificador} não está mais em $exigida.", 409);
                    }
                    $mudanca = ['situacao' => $resultante, 'local_estoque_id' => $local];
                    $anterior = $item->estado_conservacao;
                    if ($estado && $estado !== $anterior) {
                        $conservacao[] = ['item_id' => $item->id, 'anterior' => $anterior, 'novo' => $estado];
                        $mudanca['estado_conservacao'] = $estado;
                    }
                    $item->update($mudanca);
                    // Devolução: o estado de cada item fica no próprio registro (padrão = estado atual).
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'item_individual_id' => $item->id, 'quantidade' => 1]
                        + ($tipo === 'DEVOLUCAO' ? ['estado_conservacao' => $estado ?: $anterior, 'estado_anterior' => $anterior] : []));
                    if ($tipo === 'DEVOLUCAO' && $estado && $estado !== $anterior) {
                        $this->auditoria->registrar('item.conservacao_alterada', 'SUCESSO', 'item_individual', $item->id,
                            LocalEstoque::whereKey($local)->value('unidade_id'), ['antes' => $anterior, 'depois' => $estado,
                                'justificativa' => "Estado informado na devolução da entrega #{$ab->id}", 'movimentacao_id' => $mov->id]);
                    }
                }
                foreach ($porLote as $loteId => $qtd) {
                    $saldo = $this->estoque->saldoBloqueado($local, $loteId, criar: false) ?? throw new \RuntimeException('Saldo inexistente.');
                    if ($credita) {
                        $saldo->quantidade += $qtd;
                    } else {
                        $saldo->quantidade_reservada -= $qtd; // LIBERACAO_RESERVA
                    }
                    $saldo->save();
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'lote_id' => $loteId, 'quantidade' => $qtd]);
                }
                $this->auditoria->registrar('estoque.'.strtolower($tipo), 'SUCESSO', 'movimentacao', $mov->id,
                    LocalEstoque::whereKey($local)->value('unidade_id'), ['vinculo_id' => $ab->id, 'parcial' => ! $final,
                        'conservacao' => $conservacao ?: null, 'regra' => $prov ? 'PROVISORIA' : 'DECISAO_RESPONSAVEL']
                        + ($tipo === 'DEVOLUCAO' ? ['devolvido_por_militar_id' => $devolvidoPor, 'devolvido_por_nome' => $extra['devolvido_por_nome'] ?? null,
                            'observacao' => $justificativa] : [])
                        + ($retroativa ? ['retroativa' => true, 'ocorrido_em' => $ocorrido->toIso8601String()] : []));

                return $mov;
            }, 5);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'vinculo_id')) {
                throw new SigarbException('JA_CONCLUIDA', 'Esta operação já foi concluída.', 409);
            }

            return $this->estoque->tratarUnicidade($e, $chave, $u, $hash);
        }

        return [$mov, false];
    }

    /**
     * O que ainda falta concluir na abertura (já bloqueada), descontadas as devoluções parciais, e o recorte pedido.
     *
     * @return array{0: array<int, ?string>, 1: array<int, int>, 2: bool} individuais (id => conservação), lotes (id => qtd), conclui tudo?
     */
    public function restante(Movimentacao $ab, ?array $selecao): array
    {
        $parciais = MovimentacaoItem::whereIn('movimentacao_id', Movimentacao::where('vinculo_parcial_id', $ab->id)->select('id'))->get();
        $devolvidos = $parciais->pluck('item_individual_id')->filter()->flip();
        $ind = [];
        $lot = [];
        foreach ($ab->itens()->orderBy('id')->get() as $mi) {
            if ($mi->item_individual_id && ! $devolvidos->has($mi->item_individual_id)) {
                $ind[$mi->item_individual_id] = null;
            } elseif ($mi->lote_id) {
                $lot[$mi->lote_id] = ($lot[$mi->lote_id] ?? 0) + $mi->quantidade;
            }
        }
        foreach ($parciais->whereNotNull('lote_id') as $p) {
            $lot[$p->lote_id] -= $p->quantidade;
        }
        $lot = array_filter($lot);
        if ($selecao === null) {
            ksort($ind);
            ksort($lot);

            return [$ind, $lot, true];
        }
        $pInd = [];
        $pLot = [];
        foreach ($selecao as $s) {
            if (! empty($s['item_individual_id'])) {
                $id = (int) $s['item_individual_id'];
                if (! array_key_exists($id, $ind) || array_key_exists($id, $pInd)) {
                    throw new SigarbException('ITEM_INVALIDO', "O item #$id não está pendente nesta entrega.", 422);
                }
                $pInd[$id] = $s['estado_conservacao'] ?? null;
            } else {
                $id = (int) $s['lote_id'];
                $pLot[$id] = ($pLot[$id] ?? 0) + (int) $s['quantidade'];
                if ($pLot[$id] > ($lot[$id] ?? 0)) {
                    throw new SigarbException('QUANTIDADE_EXCEDIDA', "A quantidade do lote #$id é maior que a pendente (".($lot[$id] ?? 0).').', 422);
                }
            }
        }
        ksort($pInd);
        ksort($pLot);
        $final = count($pInd) === count($ind) && $pLot == $lot;

        return [$pInd, $pLot, $final];
    }

    /** P-03 (decisão CP-13): material vencido ou inservível não é entregue nem reservado. */
    private function exigirAptidao(string $tipo, string $ref, mixed $validade, ?string $conservacao): void
    {
        if (! in_array($tipo, ['ENTREGA', 'RESERVA'], true)) {
            return;
        }
        if (config('sigarb.politicas.bloquear_vencido') && $validade && Carbon::parse($validade)->lt(today())) {
            throw new SigarbException('ITEM_VENCIDO', "$ref está com a validade vencida e não pode ser entregue nem reservado.", 409);
        }
        if ($conservacao === 'INSERVIVEL') {
            throw new SigarbException('ITEM_INSERVIVEL', "$ref está inservível e não pode ser entregue nem reservado.", 409);
        }
    }

    /** RN-012: reserva reduz disponibilidade (quantidade − reservada), não a quantidade física. */
    private function reservar(int $localId, int $loteId, int $qtd): void
    {
        $saldo = $this->estoque->saldoBloqueado($localId, $loteId, criar: false);
        $disponivel = $saldo ? $saldo->quantidade - $saldo->quantidade_reservada : 0;
        if (! $saldo || $disponivel < $qtd) {
            throw new SigarbException('SALDO_INSUFICIENTE', 'Saldo disponível insuficiente para a operação.', 409,
                ['lote_id' => $loteId, 'disponivel' => $disponivel, 'solicitado' => $qtd]);
        }
        $saldo->quantidade_reservada += $qtd;
        $saldo->save();
    }
}
