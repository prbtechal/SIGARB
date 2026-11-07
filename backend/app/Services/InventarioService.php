<?php

namespace App\Services;

use App\Models\Inventario;
use App\Models\InventarioItem;
use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Movimentacao;
use App\Models\MovimentacaoItem;
use App\Models\SaldoEstoque;
use App\Models\Usuario;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RF-020 / RN-016 — REGRA PROVISÓRIA (P-03/P-06).
 * EM_CONTAGEM → AGUARDANDO_APROVACAO → (AJUSTADO | REJEITADO); EM_CONTAGEM → CANCELADO.
 * Ajuste automático só para lotes; divergência de item individual fica registrada para baixa/apuração.
 * Na aprovação o saldo atual precisa ser igual ao esperado da abertura (senão: recontar).
 */
class InventarioService
{
    public function __construct(private AuditoriaService $auditoria, private EstoqueService $estoque) {}

    public function abrir(Usuario $u, LocalEstoque $local): Inventario
    {
        Operacoes::exigir('INVENTARIO');
        try {
            return DB::transaction(function () use ($u, $local) {
                $inv = Inventario::create(['codigo' => (string) Str::ulid(), 'local_estoque_id' => $local->id, 'unidade_id' => $local->unidade_id, 'aberto_por' => $u->id]);
                foreach (SaldoEstoque::where('local_estoque_id', $local->id)->where('quantidade', '>', 0)->orderBy('lote_id')->get() as $s) {
                    InventarioItem::create(['inventario_id' => $inv->id, 'lote_id' => $s->lote_id, 'esperado' => $s->quantidade]);
                }
                foreach (ItemIndividual::where('local_estoque_id', $local->id)->whereIn('situacao', ['DISPONIVEL', 'RESERVADO'])->orderBy('id')->pluck('id') as $id) {
                    InventarioItem::create(['inventario_id' => $inv->id, 'item_individual_id' => $id, 'esperado' => 1]);
                }
                $this->auditar('aberto', $inv);

                return $inv;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SigarbException('INVENTARIO_EM_CURSO', 'Já existe inventário em curso neste local.', 409);
        }
    }

    /** @param array<int, int> $contagens inventario_item_id => contado */
    public function contar(Usuario $u, Inventario $inv, array $contagens, int $versao): Inventario
    {
        return $this->transicao($inv, $versao, ['EM_CONTAGEM'], function (Inventario $inv) use ($u, $contagens) {
            foreach ($contagens as $id => $contado) {
                $item = $inv->itens()->whereKey($id)->first() ?? throw new SigarbException('ITEM_INVALIDO', 'Linha de inventário inexistente.', 422);
                if ($item->item_individual_id && $contado > 1) {
                    throw new SigarbException('CONTAGEM_INVALIDA', 'Item individual só aceita 0 ou 1.', 422);
                }
                $item->update(['contado' => $contado, 'contado_por' => $u->id]);
            }
            DB::table('inventario_contadores')->insertOrIgnore(['inventario_id' => $inv->id, 'usuario_id' => $u->id]);

            return 'contagem_salva';
        }, fn () => ['linhas' => count($contagens)]);
    }

    public function concluirContagem(Usuario $u, Inventario $inv, int $versao): Inventario
    {
        return $this->transicao($inv, $versao, ['EM_CONTAGEM'], function (Inventario $inv) use ($u) {
            if ($inv->itens()->whereNull('contado')->exists()) {
                throw new SigarbException('CONTAGEM_INCOMPLETA', 'Informe a contagem de todas as linhas antes de enviar.', 422);
            }
            $inv->fill(['estado' => 'AGUARDANDO_APROVACAO', 'contado_por' => $u->id]);

            return 'contagem_concluida';
        }, fn (Inventario $inv) => ['divergencias' => $inv->itens()->whereColumn('contado', '!=', 'esperado')->count()]);
    }

    public function cancelar(Usuario $u, Inventario $inv, string $motivo, int $versao): Inventario
    {
        return $this->transicao($inv, $versao, ['EM_CONTAGEM'], function (Inventario $inv) use ($motivo) {
            $inv->fill(['estado' => 'CANCELADO', 'observacao' => $motivo]);

            return 'cancelado';
        });
    }

    /** Segregação provisória: quem abriu ou contou não aprova o ajuste. */
    public function decidir(Usuario $u, Inventario $inv, bool $aprovar, ?string $observacao, int $versao): Inventario
    {
        Operacoes::exigir('INVENTARIO');

        return $this->transicao($inv, $versao, ['AGUARDANDO_APROVACAO'], function (Inventario $inv) use ($u, $aprovar, $observacao) {
            if (in_array($u->id, [$inv->aberto_por, $inv->contado_por], true) || self::contou($inv, $u)) {
                throw new SigarbException('SEGREGACAO_FUNCOES', 'Quem abriu ou contou o inventário não pode aprovar o ajuste.', 403);
            }
            if (! $aprovar && ! $observacao) {
                throw new SigarbException('JUSTIFICATIVA_OBRIGATORIA', 'A rejeição exige justificativa.', 422);
            }
            $inv->fill(['estado' => $aprovar ? 'AJUSTADO' : 'REJEITADO', 'decidido_por' => $u->id, 'decidido_em' => now(), 'observacao' => $observacao]);
            if ($aprovar) {
                $this->ajustar($u, $inv);
            }

            return $aprovar ? 'ajuste_aprovado' : 'ajuste_rejeitado';
        });
    }

    public static function contou(Inventario $inv, Usuario $u): bool
    {
        // Falha fechada: registro append-only OU contador atual de alguma linha.
        return DB::table('inventario_contadores')->where('inventario_id', $inv->id)->where('usuario_id', $u->id)->exists()
            || $inv->itens()->where('contado_por', $u->id)->exists();
    }

    private function ajustar(Usuario $u, Inventario $inv): void
    {
        $falta = [];
        $sobra = [];
        foreach ($inv->itens()->whereNotNull('lote_id')->whereColumn('contado', '!=', 'esperado')->orderBy('lote_id')->get() as $l) {
            $saldo = $this->estoque->saldoBloqueado($inv->local_estoque_id, $l->lote_id, criar: false);
            if (! $saldo || (int) $saldo->quantidade !== (int) $l->esperado) {
                throw new SigarbException('INVENTARIO_DESATUALIZADO', 'O saldo mudou desde a abertura do inventário. Rejeite e abra um novo.', 409);
            }
            if ($l->contado < $l->esperado) {
                if ($l->contado < $saldo->quantidade_reservada) {
                    throw new SigarbException('RESERVA_MAIOR_QUE_CONTAGEM', 'A contagem ficou abaixo da quantidade reservada. Libere a reserva antes.', 409);
                }
                $falta[$l->lote_id] = $l->esperado - $l->contado;
            } else {
                $sobra[$l->lote_id] = $l->contado - $l->esperado;
            }
            $saldo->quantidade = $l->contado;
            $saldo->save();
        }
        foreach (['falta' => $falta, 'sobra' => $sobra] as $tipo => $lotes) {
            if (! $lotes) {
                continue;
            }
            $chave = "inv-$tipo-".$inv->codigo;
            $mov = Movimentacao::create([
                'tipo' => 'AJUSTE', $tipo === 'falta' ? 'local_origem_id' : 'local_destino_id' => $inv->local_estoque_id,
                'responsavel_id' => $u->id, 'ocorrido_em' => now(), 'documento_referencia' => 'INV-'.$inv->codigo,
                'justificativa' => "Ajuste de inventário ($tipo)", 'chave_idempotencia' => $chave, 'hash_requisicao' => hash('sha256', $chave), 'provisoria' => true,
            ]);
            foreach ($lotes as $lote => $q) {
                MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'lote_id' => $lote, 'quantidade' => $q]);
            }
            $inv->{"ajuste_{$tipo}_id"} = $mov->id;
        }
    }

    private function transicao(Inventario $inv, int $versao, array $de, callable $f, ?callable $dados = null): Inventario
    {
        return DB::transaction(function () use ($inv, $versao, $de, $f, $dados) {
            $atual = Inventario::whereKey($inv->id)->lockForUpdate()->firstOrFail();
            if ($atual->versao !== $versao) {
                throw new SigarbException('VERSAO_DESATUALIZADA', 'O inventário foi alterado por outra pessoa. Recarregue e tente de novo.', 409);
            }
            if (! in_array($atual->estado, $de, true)) {
                throw new SigarbException('TRANSICAO_INVALIDA', "Ação não permitida no estado {$atual->estado}.", 409);
            }
            $evento = $f($atual);
            $atual->versao++;
            $atual->save();
            if ($evento) {
                $this->auditar($evento, $atual, $dados ? $dados($atual) : []);
            }

            return $atual;
        });
    }

    private function auditar(string $evento, Inventario $inv, array $dados = []): void
    {
        $this->auditoria->registrar("inventario.$evento", 'SUCESSO', 'inventario', $inv->id, $inv->unidade_id,
            ['codigo' => $inv->codigo, 'estado' => $inv->estado, 'regra' => 'PROVISORIA'] + $dados);
    }
}
