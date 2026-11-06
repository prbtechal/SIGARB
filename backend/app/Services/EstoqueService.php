<?php

namespace App\Services;

use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Lote;
use App\Models\Material;
use App\Models\Movimentacao;
use App\Models\MovimentacaoItem;
use App\Models\SaldoEstoque;
use App\Models\Usuario;
use App\Support\SigarbException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único ponto de escrita de saldo e razão (ADR-005).
 * RF-011/RF-013, RN-001..RN-005, RN-017, RN-018, RNF-06.
 */
class EstoqueService
{
    public function __construct(private AuditoriaService $auditoria) {}

    /** @return array{0: Movimentacao, 1: bool} [movimentação, foiReplay] */
    public function registrarEntrada(Usuario $u, array $d, string $chave): array
    {
        $this->exigirHabilitada('ENTRADA');
        $hash = $this->hashRequisicao('ENTRADA', $d);
        if ($m = $this->replay($chave, $u, $hash)) {
            return [$m, true];
        }

        $local = LocalEstoque::findOrFail($d['local_destino_id']);
        if (! $local->ativo) {
            throw new SigarbException('LOCAL_INATIVO', 'O local de destino está inativo.', 422);
        }
        $materiais = Material::whereIn('id', array_column($d['itens'], 'material_id'))->get()->keyBy('id');
        $this->validarItensEntrada($d['itens'], $materiais);

        // Lotes e linhas de saldo (vazias, idempotentes) são criados ANTES da transação, em autocommit.
        // Assim a transação só faz FOR UPDATE em linhas existentes (locks de registro, sem gap locks),
        // evitando ciclos de deadlock entre entradas simultâneas no mesmo lote.
        $lotes = [];
        foreach ($d['itens'] as $n => $i) {
            $mat = $materiais[$i['material_id']];
            if ($mat->modo_controle === Material::LOTE) {
                $lotes[$n] = $this->obterLote($mat, $i);
                $this->garantirSaldo($local->id, $lotes[$n]->id);
            }
        }

        try {
            $mov = DB::transaction(function () use ($u, $d, $chave, $hash, $local, $materiais, $lotes) {
                $mov = Movimentacao::create([
                    'tipo' => 'ENTRADA',
                    'local_destino_id' => $local->id,
                    'responsavel_id' => $u->id,
                    'ocorrido_em' => now(),
                    'origem_descricao' => $d['origem_descricao'],
                    'documento_referencia' => $d['documento_referencia'],
                    'justificativa' => $d['justificativa'] ?? null,
                    'chave_idempotencia' => $chave,
                    'hash_requisicao' => $hash,
                ]);

                $porLote = [];
                foreach ($d['itens'] as $n => $i) {
                    $mat = $materiais[$i['material_id']];
                    if ($mat->modo_controle === Material::INDIVIDUAL) {
                        $item = ItemIndividual::create([
                            'material_id' => $mat->id,
                            'identificador' => $i['identificador'],
                            'numero_serie' => $i['numero_serie'] ?? null,
                            'local_estoque_id' => $local->id,
                            'situacao' => 'DISPONIVEL',
                            'estado_conservacao' => $i['estado_conservacao'] ?? 'BOM',
                        ]);
                        MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'item_individual_id' => $item->id, 'quantidade' => 1]);
                    } else {
                        $lote = $lotes[$n];
                        MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'lote_id' => $lote->id, 'quantidade' => (int) $i['quantidade']]);
                        $porLote[$lote->id] = ($porLote[$lote->id] ?? 0) + (int) $i['quantidade'];
                    }
                }

                ksort($porLote); // ordem determinística de locks
                foreach ($porLote as $loteId => $qtd) {
                    $saldo = $this->saldoBloqueado($local->id, $loteId, criar: false);
                    $saldo->quantidade += $qtd;
                    $saldo->save();
                }

                $this->auditoria->registrar('estoque.entrada', 'SUCESSO', 'movimentacao', $mov->id, $local->unidade_id, [
                    'local_destino_id' => $local->id, 'itens' => count($d['itens']), 'documento_referencia' => $d['documento_referencia'],
                ]);

                return $mov;
            }, 5);
        } catch (UniqueConstraintViolationException $e) {
            return $this->tratarUnicidade($e, $chave, $u, $hash);
        }

        return [$mov, false];
    }

    private function garantirSaldo(int $localId, int $loteId): void
    {
        DB::table('saldos_estoque')->insertOrIgnore([
            'local_estoque_id' => $localId, 'lote_id' => $loteId, 'quantidade' => 0, 'quantidade_reservada' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** RN-018: correção por estorno vinculado; o evento original permanece. */
    public function estornar(Usuario $u, Movimentacao $original, string $justificativa, string $chave): array
    {
        $this->exigirHabilitada('ESTORNO');
        $hash = $this->hashRequisicao('ESTORNO', ['estorno_de_id' => $original->id, 'justificativa' => $justificativa]);
        if ($m = $this->replay($chave, $u, $hash)) {
            return [$m, true];
        }

        try {
            $mov = DB::transaction(function () use ($u, $original, $justificativa, $chave, $hash) {
                $orig = Movimentacao::whereKey($original->id)->lockForUpdate()->firstOrFail();
                if ($orig->tipo !== 'ENTRADA') {
                    throw new SigarbException('ESTORNO_INVALIDO', 'No MVP somente movimentações de ENTRADA podem ser estornadas.', 422);
                }
                if (Movimentacao::where('estorno_de_id', $orig->id)->exists()) {
                    throw new SigarbException('JA_ESTORNADA', 'Esta movimentação já foi estornada.', 409);
                }
                $local = LocalEstoque::findOrFail($orig->local_destino_id);

                $mov = Movimentacao::create([
                    'tipo' => 'ESTORNO',
                    'local_origem_id' => $local->id,
                    'responsavel_id' => $u->id,
                    'ocorrido_em' => now(),
                    'justificativa' => $justificativa,
                    'documento_referencia' => $orig->documento_referencia,
                    'chave_idempotencia' => $chave,
                    'hash_requisicao' => $hash,
                    'estorno_de_id' => $orig->id,
                ]);

                $itens = $orig->itens()->orderBy('id')->get();
                $porLote = [];
                foreach ($itens->whereNotNull('item_individual_id')->sortBy('item_individual_id') as $mi) {
                    $item = ItemIndividual::whereKey($mi->item_individual_id)->lockForUpdate()->firstOrFail();
                    if ($item->situacao !== 'DISPONIVEL' || $item->local_estoque_id !== $local->id) {
                        throw new SigarbException('ESTORNO_IMPEDIDO',
                            "O item {$item->identificador} não está disponível no local de origem; o estorno exigiria movimentação posterior.", 409);
                    }
                    $item->update(['situacao' => 'ESTORNADO']);
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'item_individual_id' => $item->id, 'quantidade' => 1]);
                }
                foreach ($itens->whereNotNull('lote_id') as $mi) {
                    $porLote[$mi->lote_id] = ($porLote[$mi->lote_id] ?? 0) + $mi->quantidade;
                }
                ksort($porLote);
                foreach ($porLote as $loteId => $qtd) {
                    $this->debitarLote($local->id, $loteId, $qtd);
                    MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'lote_id' => $loteId, 'quantidade' => $qtd]);
                }

                $this->auditoria->registrar('estoque.estorno', 'SUCESSO', 'movimentacao', $mov->id, $local->unidade_id, [
                    'estorno_de_id' => $orig->id, 'justificativa' => $justificativa,
                ]);

                return $mov;
            }, 5);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'estorno_de_id')) {
                throw new SigarbException('JA_ESTORNADA', 'Esta movimentação já foi estornada.', 409);
            }

            return $this->tratarUnicidade($e, $chave, $u, $hash);
        }

        return [$mov, false];
    }

    /**
     * RN-002: nenhuma operação gera saldo negativo. Deve ser chamado dentro de transação.
     * Considera disponível = quantidade − reservada (RN-012).
     */
    public function debitarLote(int $localId, int $loteId, int $qtd): SaldoEstoque
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('debitarLote exige transação ativa.');
        }
        $saldo = $this->saldoBloqueado($localId, $loteId, criar: false);
        $disponivel = $saldo ? $saldo->quantidade - $saldo->quantidade_reservada : 0;
        if (! $saldo || $disponivel < $qtd) {
            throw new SigarbException('SALDO_INSUFICIENTE', 'Saldo disponível insuficiente para a operação.', 409,
                ['lote_id' => $loteId, 'disponivel' => $disponivel, 'solicitado' => $qtd]);
        }
        $saldo->quantidade -= $qtd;
        $saldo->save();

        return $saldo;
    }

    private function saldoBloqueado(int $localId, int $loteId, bool $criar): ?SaldoEstoque
    {
        if ($criar) {
            DB::table('saldos_estoque')->insertOrIgnore([
                'local_estoque_id' => $localId, 'lote_id' => $loteId, 'quantidade' => 0, 'quantidade_reservada' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return SaldoEstoque::where('local_estoque_id', $localId)->where('lote_id', $loteId)->lockForUpdate()->first();
    }

    private function obterLote(Material $mat, array $i): Lote
    {
        DB::table('lotes')->insertOrIgnore([
            'material_id' => $mat->id, 'codigo' => $i['lote_codigo'], 'validade' => $i['validade'] ?? null,
            'fabricante_id' => $i['fabricante_id'] ?? $mat->fabricante_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lote = Lote::where('material_id', $mat->id)->where('codigo', $i['lote_codigo'])->firstOrFail();
        $informada = $i['validade'] ?? null;
        if ($informada !== null && $lote->validade?->format('Y-m-d') !== $informada) {
            throw ValidationException::withMessages(['itens' => ["O lote {$lote->codigo} já existe com outra validade (RN-004)."]]);
        }

        return $lote;
    }

    private function validarItensEntrada(array $itens, $materiais): void
    {
        $erros = [];
        foreach ($itens as $n => $i) {
            $mat = $materiais[$i['material_id']] ?? null;
            if (! $mat || ! $mat->ativo) {
                $erros["itens.$n.material_id"] = ['Material inexistente ou inativo.'];
                continue;
            }
            if ($mat->modo_controle === Material::INDIVIDUAL) {
                if (empty($i['identificador'])) {
                    $erros["itens.$n.identificador"] = ['Item individual exige identificador único (RN-003).'];
                }
                if (! empty($i['lote_codigo']) || (isset($i['quantidade']) && (int) $i['quantidade'] !== 1)) {
                    $erros["itens.$n.quantidade"] = ['Item individual não aceita lote nem quantidade diferente de 1.'];
                }
            } else {
                if (empty($i['lote_codigo'])) {
                    $erros["itens.$n.lote_codigo"] = ['Material controlado por lote exige o código do lote (RN-004).'];
                }
                if (empty($i['quantidade']) || (int) $i['quantidade'] < 1) {
                    $erros["itens.$n.quantidade"] = ['Quantidade deve ser inteira e positiva.'];
                }
                if (! empty($i['identificador'])) {
                    $erros["itens.$n.identificador"] = ['Material por lote não aceita identificador individual.'];
                }
            }
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
    }

    private function exigirHabilitada(string $op): void
    {
        if (config("sigarb.operacoes.$op.estado") !== 'HABILITADA') {
            throw SigarbException::operacaoBloqueada($op);
        }
    }

    public function hashRequisicao(string $tipo, array $d): string
    {
        $normalizar = function ($v) use (&$normalizar) {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v);
                }

                return array_map($normalizar, $v);
            }

            return $v;
        };

        return hash('sha256', $tipo.'|'.json_encode($normalizar($d)));
    }

    private function replay(string $chave, Usuario $u, string $hash): ?Movimentacao
    {
        $m = Movimentacao::where('chave_idempotencia', $chave)->first();
        if (! $m) {
            return null;
        }
        if ($m->responsavel_id !== $u->id || ! hash_equals($m->hash_requisicao, $hash)) {
            throw new SigarbException('IDEMPOTENCIA_CONFLITO', 'A chave de idempotência já foi usada com outro conteúdo.', 409);
        }

        return $m;
    }

    private function tratarUnicidade(UniqueConstraintViolationException $e, string $chave, Usuario $u, string $hash): array
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'chave_idempotencia')) {
            return [$this->replay($chave, $u, $hash) ?? throw $e, true];
        }
        if (str_contains($msg, 'identificador') || str_contains($msg, 'numero_serie')) {
            throw new SigarbException('IDENTIFICADOR_DUPLICADO', 'Identificador patrimonial ou número de série já cadastrado (RN-003).', 422);
        }
        throw $e;
    }
}
