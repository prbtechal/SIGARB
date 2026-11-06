<?php

namespace App\Services;

use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Movimentacao;
use App\Models\MovimentacaoItem;
use App\Models\Usuario;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
    ];

    /** conclusão => [abertura, situação exigida, situação resultante] */
    public const CONCLUSOES = [
        'RECEBIMENTO' => ['TRANSFERENCIA', 'EM_TRANSITO', 'DISPONIVEL'],
        'LIBERACAO_RESERVA' => ['RESERVA', 'RESERVADO', 'DISPONIVEL'],
        'RETORNO_MANUTENCAO' => ['MANUTENCAO', 'MANUTENCAO', 'DISPONIVEL'],
    ];

    public function __construct(private EstoqueService $estoque, private AuditoriaService $auditoria) {}

    /** @return array{0: Movimentacao, 1: bool} */
    public function abrir(Usuario $u, string $tipo, array $d, string $chave): array
    {
        [$exigida, $resultante, $op] = self::ABERTURAS[$tipo];
        Operacoes::exigir($op);
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

        try {
            $mov = DB::transaction(function () use ($u, $tipo, $d, $chave, $hash, $origem, $destino, $individuais, $lotes, $exigida, $resultante) {
                $mov = Movimentacao::create([
                    'tipo' => $tipo, 'local_origem_id' => $origem->id, 'local_destino_id' => $destino?->id,
                    'responsavel_id' => $u->id, 'ocorrido_em' => now(),
                    'origem_descricao' => $d['destinacao'] ?? null, 'documento_referencia' => $d['documento_referencia'] ?? null,
                    'justificativa' => $d['justificativa'], 'chave_idempotencia' => $chave, 'hash_requisicao' => $hash, 'provisoria' => true,
                ]);
                foreach ($individuais as $id) {
                    $item = ItemIndividual::whereKey($id)->lockForUpdate()->first();
                    if (! $item || $item->local_estoque_id !== $origem->id || ! in_array($item->situacao, $exigida, true)) {
                        throw new SigarbException('ITEM_INDISPONIVEL', 'Item '.($item->identificador ?? "#$id").' não está em situação válida no local de origem.', 409);
                    }
                    $item->update(['situacao' => $resultante]);
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'item_individual_id' => $item->id, 'quantidade' => 1]);
                }
                foreach ($lotes as $loteId => $qtd) {
                    if ($tipo === 'RESERVA') {
                        $this->reservar($origem->id, $loteId, $qtd);
                    } else {
                        $this->estoque->debitarLote($origem->id, $loteId, $qtd);
                    }
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'lote_id' => $loteId, 'quantidade' => $qtd]);
                }
                $this->auditoria->registrar('estoque.'.strtolower($tipo), 'SUCESSO', 'movimentacao', $mov->id, $origem->unidade_id, [
                    'local_origem_id' => $origem->id, 'local_destino_id' => $destino?->id, 'itens' => count($d['itens']),
                    'documento_referencia' => $d['documento_referencia'] ?? null, 'regra' => 'PROVISORIA',
                ]);

                return $mov;
            }, 5);
        } catch (UniqueConstraintViolationException $e) {
            return $this->estoque->tratarUnicidade($e, $chave, $u, $hash);
        }

        return [$mov, false];
    }

    /** @return array{0: Movimentacao, 1: bool} */
    public function concluir(Usuario $u, string $tipo, Movimentacao $abertura, ?string $justificativa, string $chave): array
    {
        [$tipoAbertura, $exigida, $resultante] = self::CONCLUSOES[$tipo];
        Operacoes::exigir(self::ABERTURAS[$tipoAbertura][2]);
        $hash = $this->estoque->hashRequisicao($tipo, ['vinculo_id' => $abertura->id, 'justificativa' => $justificativa]);
        if ($m = $this->estoque->replay($chave, $u, $hash)) {
            return [$m, true];
        }
        if ($abertura->tipo !== $tipoAbertura) {
            throw new SigarbException('VINCULO_INVALIDO', "A movimentação vinculada não é do tipo $tipoAbertura.", 422);
        }
        $local = $tipo === 'RECEBIMENTO' ? $abertura->local_destino_id : $abertura->local_origem_id;
        if ($tipo === 'RECEBIMENTO' && ! LocalEstoque::whereKey($local)->value('ativo')) {
            throw new SigarbException('LOCAL_INATIVO', 'O local de destino está inativo.', 422);
        }
        $itens = $abertura->itens()->orderBy('id')->get();
        if ($tipo === 'RECEBIMENTO') {
            foreach ($itens->whereNotNull('lote_id') as $mi) {
                $this->estoque->garantirSaldo($local, $mi->lote_id); // fora da transação (ver EstoqueService)
            }
        }

        try {
            $mov = DB::transaction(function () use ($u, $tipo, $abertura, $justificativa, $chave, $hash, $local, $itens, $exigida, $resultante) {
                $ab = Movimentacao::whereKey($abertura->id)->lockForUpdate()->firstOrFail();
                if (Movimentacao::where('vinculo_id', $ab->id)->exists()) {
                    throw new SigarbException('JA_CONCLUIDA', 'Esta operação já foi concluída.', 409);
                }
                $mov = Movimentacao::create([
                    'tipo' => $tipo, 'local_origem_id' => $ab->local_origem_id, 'local_destino_id' => $tipo === 'RECEBIMENTO' ? $local : null,
                    'responsavel_id' => $u->id, 'ocorrido_em' => now(), 'documento_referencia' => $ab->documento_referencia,
                    'justificativa' => $justificativa, 'chave_idempotencia' => $chave, 'hash_requisicao' => $hash,
                    'vinculo_id' => $ab->id, 'provisoria' => true,
                ]);
                foreach ($itens->whereNotNull('item_individual_id')->sortBy('item_individual_id') as $mi) {
                    $item = ItemIndividual::whereKey($mi->item_individual_id)->lockForUpdate()->firstOrFail();
                    if ($item->situacao !== $exigida) {
                        throw new SigarbException('ITEM_INDISPONIVEL', "O item {$item->identificador} não está mais em $exigida.", 409);
                    }
                    $item->update(['situacao' => $resultante, 'local_estoque_id' => $local]);
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'item_individual_id' => $item->id, 'quantidade' => 1]);
                }
                $porLote = [];
                foreach ($itens->whereNotNull('lote_id') as $mi) {
                    $porLote[$mi->lote_id] = ($porLote[$mi->lote_id] ?? 0) + $mi->quantidade;
                }
                ksort($porLote);
                foreach ($porLote as $loteId => $qtd) {
                    $saldo = $this->estoque->saldoBloqueado($local, $loteId, criar: false) ?? throw new \RuntimeException('Saldo inexistente.');
                    if ($tipo === 'RECEBIMENTO') {
                        $saldo->quantidade += $qtd;
                    } else {
                        $saldo->quantidade_reservada -= $qtd; // LIBERACAO_RESERVA
                    }
                    $saldo->save();
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'lote_id' => $loteId, 'quantidade' => $qtd]);
                }
                $this->auditoria->registrar('estoque.'.strtolower($tipo), 'SUCESSO', 'movimentacao', $mov->id,
                    LocalEstoque::whereKey($local)->value('unidade_id'), ['vinculo_id' => $ab->id, 'regra' => 'PROVISORIA']);

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
