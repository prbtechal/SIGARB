<?php

namespace App\Services;

use App\Models\Acautelamento;
use App\Models\Inspecao;
use App\Models\ItemIndividual;
use App\Models\Militar;
use App\Models\Movimentacao;
use App\Models\MovimentacaoItem;
use App\Models\Usuario;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RF-015..018, RN-009..011 — REGRA PROVISÓRIA (P-06/P-07/P-03).
 * SOLICITADO → (APROVADO | REJEITADO | CANCELADO); APROVADO → (ATIVO | CANCELADO); ATIVO → ENCERRADO.
 * Toda transição trava a linha (FOR UPDATE) e confere a versão enviada pelo cliente (409 se mudou).
 */
class AcautelamentoService
{
    public function __construct(private AuditoriaService $auditoria) {}

    public function solicitar(Usuario $u, array $d): Acautelamento
    {
        Operacoes::exigir('ACAUTELAMENTO');
        $militar = Militar::findOrFail($d['militar_id']);
        if ($militar->situacao !== 'ATIVO') {
            throw new SigarbException('EFETIVO_INATIVO', 'Somente efetivo ativo pode receber acautelamento.', 422);
        }
        try {
            return DB::transaction(function () use ($u, $d, $militar) {
                $item = ItemIndividual::with('local')->whereKey($d['item_individual_id'])->lockForUpdate()->firstOrFail();
                if ($item->situacao !== 'DISPONIVEL') {
                    throw new SigarbException('ITEM_INDISPONIVEL', "O item {$item->identificador} não está disponível.", 409);
                }
                $a = Acautelamento::create([
                    'codigo' => (string) Str::ulid(), 'militar_id' => $militar->id, 'item_individual_id' => $item->id,
                    'unidade_id' => $item->local->unidade_id, 'solicitante_id' => $u->id, 'justificativa' => $d['justificativa'],
                ]);
                $this->auditar('solicitado', $a);

                return $a;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SigarbException('ITEM_EM_ACAUTELAMENTO', 'Este item já tem um acautelamento em curso.', 409);
        }
    }

    /** RN-009 / P-06 provisório: quem decide não pode ser quem pediu; rejeição exige justificativa. */
    public function decidir(Usuario $u, Acautelamento $a, bool $aprovar, ?string $justificativa, int $versao): Acautelamento
    {
        Operacoes::exigir('ACAUTELAMENTO');

        return $this->transicao($a, $versao, ['SOLICITADO'], function (Acautelamento $a) use ($u, $aprovar, $justificativa) {
            $auto = $a->solicitante_id === $u->id;
            if ($auto && ! app(AutorizacaoService::class)->temPapel($u, config('sigarb.politicas.autoaprovacao_papeis'), $a->unidade_id)) {
                throw new SigarbException('SEGREGACAO_FUNCOES', 'Quem solicitou o acautelamento não pode decidi-lo.', 403);
            }
            if (! $aprovar && ! $justificativa) {
                throw new SigarbException('JUSTIFICATIVA_OBRIGATORIA', 'A rejeição exige justificativa.', 422);
            }
            $a->fill(['estado' => $aprovar ? 'APROVADO' : 'REJEITADO', 'aprovador_id' => $u->id, 'decidido_em' => now(), 'decisao_justificativa' => $justificativa]);

            return $aprovar ? 'aprovado' : 'rejeitado';
        });
    }

    public function cancelar(Usuario $u, Acautelamento $a, string $justificativa, int $versao): Acautelamento
    {
        return $this->transicao($a, $versao, ['SOLICITADO', 'APROVADO'], function (Acautelamento $a) use ($justificativa) {
            $a->fill(['estado' => 'CANCELADO', 'decisao_justificativa' => $justificativa]);

            return 'cancelado';
        });
    }

    /** Entrega após aprovação: movimentação ENTREGA (chave idempotente derivada do código = entrega única). */
    public function entregar(Usuario $u, Acautelamento $a, int $versao): Acautelamento
    {
        Operacoes::exigir('ACAUTELAMENTO');

        return $this->transicao($a, $versao, ['APROVADO'], function (Acautelamento $a) use ($u) {
            $item = ItemIndividual::with('local')->whereKey($a->item_individual_id)->lockForUpdate()->firstOrFail();
            if ($item->situacao !== 'DISPONIVEL') {
                throw new SigarbException('ITEM_INDISPONIVEL', "O item {$item->identificador} não está mais disponível.", 409);
            }
            // O item pode ter sido transferido depois do pedido: a autorização foi dada para a unidade do pedido.
            if ($item->local->unidade_id !== $a->unidade_id) {
                throw new SigarbException('ITEM_FORA_DA_UNIDADE', 'O item mudou de unidade depois da solicitação. Cancele e solicite de novo.', 409);
            }
            $mov = $this->movimentar($u, 'ENTREGA', $a, $item->local_estoque_id, null, 'Entrega de acautelamento');
            $item->update(['situacao' => 'ACAUTELADO']);
            $a->fill(['estado' => 'ATIVO', 'entrega_id' => $mov->id, 'carteira_token' => Str::random(43),
                'proxima_inspecao' => now()->addDays(config('sigarb.acautelamento.inspecao_dias'))->toDateString()]);

            return 'entregue';
        });
    }

    /** RF-018 / RN-010: inspeção periódica controlada separadamente; renova o próximo prazo. */
    public function inspecionar(Usuario $u, Acautelamento $a, string $resultado, ?string $observacao, int $versao): Acautelamento
    {
        Operacoes::exigir('INSPECAO');

        return $this->transicao($a, $versao, ['ATIVO'], function (Acautelamento $a) use ($u, $resultado, $observacao) {
            Inspecao::create(['acautelamento_id' => $a->id, 'inspetor_id' => $u->id, 'realizada_em' => now(), 'resultado' => $resultado, 'observacao' => $observacao]);
            $a->proxima_inspecao = now()->addDays(config('sigarb.acautelamento.inspecao_dias'))->toDateString();

            return 'inspecionado';
        }, ['resultado' => $resultado]);
    }

    /** RN-011: desacautelamento registra data, responsável, condição e divergências; revoga a carteira. */
    public function desacautelar(Usuario $u, Acautelamento $a, string $conservacao, ?string $divergencias, int $versao): Acautelamento
    {
        Operacoes::exigir('DESACAUTELAMENTO');

        return $this->transicao($a, $versao, ['ATIVO'], function (Acautelamento $a) use ($u, $conservacao, $divergencias) {
            $item = ItemIndividual::whereKey($a->item_individual_id)->lockForUpdate()->firstOrFail();
            $mov = $this->movimentar($u, 'DEVOLUCAO', $a, $item->local_estoque_id, $a->entrega_id,
                trim("Desacautelamento. Condição: $conservacao. ".($divergencias ? "Divergências: $divergencias" : 'Sem divergências.')));
            $item->update(['situacao' => 'DISPONIVEL', 'estado_conservacao' => $conservacao]);
            $a->fill(['estado' => 'ENCERRADO', 'devolucao_id' => $mov->id, 'carteira_token' => null]);

            return 'encerrado';
        }, ['estado_conservacao' => $conservacao, 'com_divergencias' => (bool) $divergencias]);
    }

    private function transicao(Acautelamento $a, int $versao, array $de, callable $f, array $dados = []): Acautelamento
    {
        return DB::transaction(function () use ($a, $versao, $de, $f, $dados) {
            $atual = Acautelamento::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($atual->versao !== $versao) {
                throw new SigarbException('VERSAO_DESATUALIZADA', 'O acautelamento foi alterado por outra pessoa. Recarregue e tente de novo.', 409);
            }
            if (! in_array($atual->estado, $de, true)) {
                throw new SigarbException('TRANSICAO_INVALIDA', "Ação não permitida no estado {$atual->estado}.", 409);
            }
            $evento = $f($atual);
            $atual->versao++;
            $atual->save();
            $this->auditar($evento, $atual, $dados);

            return $atual;
        });
    }

    private function movimentar(Usuario $u, string $tipo, Acautelamento $a, int $local, ?int $vinculo, string $just): Movimentacao
    {
        $chave = 'acaut-'.strtolower($tipo).'-'.$a->codigo;
        $mov = Movimentacao::create([
            'tipo' => $tipo, 'local_origem_id' => $local, 'responsavel_id' => $u->id, 'militar_id' => $a->militar_id, 'ocorrido_em' => now(),
            'documento_referencia' => 'ACAUT-'.$a->codigo, 'justificativa' => mb_substr($just, 0, 500), 'chave_idempotencia' => $chave,
            'hash_requisicao' => hash('sha256', $chave), 'vinculo_id' => $vinculo, 'provisoria' => true,
        ]);
        MovimentacaoItem::create(['movimentacao_id' => $mov->id, 'item_individual_id' => $a->item_individual_id, 'quantidade' => 1]);

        return $mov;
    }

    /** Cada evento grava quem pediu, quem aprovou, quem operou, para quem e o quê — mesmo se o cadastro mudar depois. */
    private function auditar(string $evento, Acautelamento $a, array $dados = []): void
    {
        $a->loadMissing(['solicitante', 'aprovador', 'militar.postoGraduacao', 'item.material.categoria', 'item.material.calibre', 'item.material.tipo']);
        $pessoa = fn (?Usuario $p) => $p ? ['id' => $p->id, 'nome' => $p->nome] : null;
        $item = $a->item;
        $mat = $item?->material;
        $this->auditoria->registrar("acautelamento.$evento", 'SUCESSO', 'acautelamento', $a->id, $a->unidade_id, [
            'codigo' => $a->codigo, 'estado' => $a->estado, 'regra' => 'PROVISORIA',
            'operado_por' => $pessoa(auth()->user()),
            'solicitante' => $pessoa($a->solicitante),
            'aprovador' => $pessoa($a->aprovador),
            'autoaprovacao' => $a->aprovador_id !== null && $a->aprovador_id === $a->solicitante_id,
            'decidido_em' => $a->decidido_em?->toIso8601String(),
            'militar' => $a->militar ? ['id' => $a->militar->id, 'matricula' => $a->militar->matricula,
                'posto' => $a->militar->postoGraduacao?->sigla, 'nome_guerra' => $a->militar->nome_guerra] : null,
            'material' => $mat ? ['codigo' => $mat->codigo, 'nome' => $mat->nome, 'categoria' => $mat->categoria?->nome,
                'tipo' => $mat->tipo?->nome, 'calibre' => $mat->calibre?->nome] : null,
            'item' => $item ? ['identificador' => $item->identificador, 'numero_serie' => $item->numero_serie] : null,
            'quantidade' => 1,
        ] + $dados);
    }
}
