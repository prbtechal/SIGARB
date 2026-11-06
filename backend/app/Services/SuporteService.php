<?php

namespace App\Services;

use App\Models\Chamado;
use App\Models\ChamadoMensagem;
use App\Models\Notificacao;
use App\Models\Usuario;
use App\Support\SigarbException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Central de Suporte. Regras:
 * - visibilidade: solicitante vê o próprio chamado; atendimento (`suporte.atender`) vê chamados no seu escopo de unidades;
 *   qualquer outro caso é 404 (sem revelar existência);
 * - toda mudança grava evento de auditoria NA MESMA TRANSAÇÃO (fail-closed: sem auditoria, sem mudança);
 * - ações de atendimento exigem a `versao` lida (concorrência otimista → 409);
 * - textos passam por redação de segredos antes de gravar; auditoria nunca recebe o texto livre.
 */
class SuporteService
{
    public const DIAS_REABERTURA = 15;

    public const MAX_REABERTURAS = 3;

    public function __construct(private AuditoriaService $auditoria, private AutorizacaoService $authz) {}

    public function atende(Usuario $u, ?Chamado $c = null): bool
    {
        return $c === null ? $this->authz->pode($u, 'suporte.atender')
            : ($c->unidade_id === null ? $this->authz->global($u, 'suporte.atender') : $this->authz->pode($u, 'suporte.atender', $c->unidade_id));
    }

    /** Solicitante ou atendimento no escopo; caso contrário 404 (sem enumeração). */
    public function visivel(Usuario $u, string $codigo): Chamado
    {
        $c = Chamado::where('codigo', $codigo)->first();
        if ($c === null || ($c->solicitante_id !== $u->id && ! $this->atende($u, $c))) {
            throw new SigarbException('NAO_ENCONTRADO', 'Recurso não encontrado.', 404);
        }

        return $c;
    }

    public function abrir(Usuario $u, array $d, array $diagnostico): Chamado
    {
        [$assunto] = $this->redigir($d['assunto']);
        [$corpo, $red] = $this->redigir($d['descricao']);
        // Unidade do chamado = unidade da primeira atribuição de papel do solicitante (null = global). Define quem atende.
        $unidade = $u->atribuicoes()->orderBy('id')->value('unidade_id');

        return DB::transaction(function () use ($u, $d, $assunto, $corpo, $red, $unidade, $diagnostico) {
            $c = Chamado::create([
                'codigo' => (string) Str::ulid(), 'protocolo' => $this->protocolo(), 'solicitante_id' => $u->id, 'unidade_id' => $unidade,
                'categoria' => $d['categoria'], 'prioridade' => $d['prioridade'], 'status' => 'ABERTO', 'assunto' => $assunto,
                'diagnostico' => $diagnostico,
            ]);
            ChamadoMensagem::create(['chamado_id' => $c->id, 'autor_id' => $u->id, 'tipo' => 'PUBLICA', 'corpo' => $corpo, 'redigido' => $red]);
            $this->auditoria->registrar('suporte.chamado.aberto', 'SUCESSO', 'chamado', $c->id, $c->unidade_id,
                ['protocolo' => $c->protocolo, 'categoria' => $c->categoria, 'prioridade' => $c->prioridade, 'redigido' => $red]);

            return $c;
        });
    }

    /** Mensagem do solicitante (sempre pública) ou do atendimento (pública ou interna). */
    public function responder(Usuario $u, Chamado $c, string $texto, string $tipo): ChamadoMensagem
    {
        $atendente = $this->atende($u, $c);
        if ($tipo === 'INTERNA' && ! $atendente) {
            throw new SigarbException('ACESSO_NEGADO', 'Nota interna é exclusiva do atendimento.', 403);
        }
        [$corpo, $red] = $this->redigir($texto);

        return DB::transaction(function () use ($u, $c, $corpo, $red, $tipo, $atendente) {
            $c = Chamado::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if (in_array($c->status, ['RESOLVIDO', 'CANCELADO'], true)) {
                throw new SigarbException('CHAMADO_ENCERRADO', 'Chamado encerrado. Reabra-o para continuar a conversa.', 409);
            }
            $m = ChamadoMensagem::create(['chamado_id' => $c->id, 'autor_id' => $u->id, 'tipo' => $tipo, 'corpo' => $corpo, 'redigido' => $red]);
            $doSolicitante = $u->id === $c->solicitante_id && ! ($atendente && $tipo === 'INTERNA');
            if ($doSolicitante && $c->status === 'AGUARDANDO_SOLICITANTE') {
                $c->status = $c->atribuido_a_id ? 'EM_ATENDIMENTO' : 'ABERTO';
            }
            $c->versao++;
            $c->save();
            $this->auditoria->registrar($tipo === 'INTERNA' ? 'suporte.nota_interna' : 'suporte.resposta', 'SUCESSO', 'chamado', $c->id, $c->unidade_id,
                ['protocolo' => $c->protocolo, 'mensagem_id' => $m->id, 'redigido' => $red]);
            if ($tipo === 'PUBLICA') {
                if ($doSolicitante) {
                    $this->notificar($c->atribuido_a_id, $c, 'suporte.resposta_solicitante', "Nova mensagem no chamado {$c->protocolo}.");
                } else {
                    $this->notificar($c->solicitante_id, $c, 'suporte.resposta_atendimento', "O atendimento respondeu o chamado {$c->protocolo}.");
                }
            }

            return $m;
        });
    }

    /** Atendimento: status, prioridade e responsável, com versão obrigatória. */
    public function atualizar(Usuario $u, Chamado $c, array $d): Chamado
    {
        if (! $this->atende($u, $c)) {
            throw new SigarbException('ACESSO_NEGADO', 'Ação exclusiva do atendimento.', 403);
        }
        if (array_key_exists('atribuido_a_id', $d) && $d['atribuido_a_id'] !== null) {
            $alvo = Usuario::find($d['atribuido_a_id']);
            if ($alvo === null || ! $alvo->ativo || ! $this->atende($alvo, $c)) {
                throw new SigarbException('RESPONSAVEL_INVALIDO', 'O responsável precisa ter permissão de atendimento neste escopo.', 422);
            }
        }

        return DB::transaction(function () use ($u, $c, $d) {
            $c = $this->travarVersao($c, (int) $d['versao']);
            $antes = $c->only(['status', 'prioridade', 'atribuido_a_id']);
            if (isset($d['status']) && $d['status'] !== $c->status) {
                if (! in_array($d['status'], Chamado::TRANSICOES[$c->status], true)) {
                    throw new SigarbException('TRANSICAO_INVALIDA', "Não é possível passar de {$c->status} para {$d['status']}.", 422);
                }
                $c->status = $d['status'];
                $c->resolvido_em = $d['status'] === 'RESOLVIDO' ? now() : null;
            }
            if (isset($d['prioridade'])) {
                $c->prioridade = $d['prioridade'];
            }
            if (array_key_exists('atribuido_a_id', $d)) {
                $c->atribuido_a_id = $d['atribuido_a_id'];
            }
            $depois = $c->only(['status', 'prioridade', 'atribuido_a_id']);
            $mudou = array_diff_assoc(array_map('strval', $depois), array_map('strval', $antes));
            if ($mudou === []) {
                return $c;
            }
            $c->versao++;
            $c->save();
            $this->sistema($c, $u, $antes, $depois);
            $this->auditoria->registrar('suporte.atendimento.alterado', 'SUCESSO', 'chamado', $c->id, $c->unidade_id,
                ['protocolo' => $c->protocolo, 'antes' => array_intersect_key($antes, $mudou), 'depois' => $mudou]);
            if (isset($mudou['status'])) {
                $this->notificar($c->solicitante_id, $c, 'suporte.status', "Chamado {$c->protocolo}: situação alterada para ".self::rotuloStatus($c->status).'.');
            }
            if (isset($mudou['atribuido_a_id']) && $c->atribuido_a_id && $c->atribuido_a_id !== $u->id) {
                $this->notificar($c->atribuido_a_id, $c, 'suporte.atribuido', "Chamado {$c->protocolo} atribuído a você.");
            }

            return $c;
        });
    }

    /** Solicitante (até DIAS_REABERTURA após resolver, no máximo MAX_REABERTURAS) ou atendimento (sempre que RESOLVIDO). */
    public function reabrir(Usuario $u, Chamado $c, int $versao, string $motivo): Chamado
    {
        $atendente = $this->atende($u, $c);
        [$texto, $red] = $this->redigir($motivo);

        return DB::transaction(function () use ($u, $c, $versao, $texto, $red, $atendente) {
            $c = $this->travarVersao($c, $versao);
            if ($c->status !== 'RESOLVIDO') {
                throw new SigarbException('NAO_REABRIVEL', 'Só chamados resolvidos podem ser reabertos.', 422);
            }
            if (! $atendente) {
                if ($c->resolvido_em === null || $c->resolvido_em->lt(now()->subDays(self::DIAS_REABERTURA))) {
                    throw new SigarbException('PRAZO_REABERTURA', 'O prazo de reabertura ('.self::DIAS_REABERTURA.' dias) terminou. Abra um novo chamado.', 422);
                }
                if ($c->reaberturas >= self::MAX_REABERTURAS) {
                    throw new SigarbException('LIMITE_REABERTURA', 'Limite de reaberturas atingido. Abra um novo chamado.', 422);
                }
            }
            $c->status = $c->atribuido_a_id ? 'EM_ATENDIMENTO' : 'ABERTO';
            $c->resolvido_em = null;
            $c->reaberturas++;
            $c->versao++;
            $c->save();
            ChamadoMensagem::create(['chamado_id' => $c->id, 'autor_id' => $u->id, 'tipo' => 'PUBLICA', 'corpo' => "Chamado reaberto: {$texto}", 'redigido' => $red]);
            $this->auditoria->registrar('suporte.chamado.reaberto', 'SUCESSO', 'chamado', $c->id, $c->unidade_id,
                ['protocolo' => $c->protocolo, 'reaberturas' => $c->reaberturas, 'por_atendimento' => $atendente]);
            $u->id === $c->solicitante_id
                ? $this->notificar($c->atribuido_a_id, $c, 'suporte.reaberto', "Chamado {$c->protocolo} reaberto pelo solicitante.")
                : $this->notificar($c->solicitante_id, $c, 'suporte.reaberto', "Chamado {$c->protocolo} reaberto pelo atendimento.");

            return $c;
        });
    }

    /**
     * Remove segredos e dados pessoais óbvios antes de gravar (o texto é livre e o usuário pode colar credenciais).
     *
     * @return array{0: string, 1: bool}
     */
    public function redigir(string $t): array
    {
        $regras = [
            '/\b(senha|password|passwd|pwd|token|secret|segredo|api[_-]?key|chave)\b(\s*[:=]\s*)\S+/iu' => '$1$2[REMOVIDO]',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [REMOVIDO]',
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\b/' => '[TOKEN REMOVIDO]',
            '/\bbase64:[A-Za-z0-9+\/=]{20,}/' => '[CHAVE REMOVIDA]',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[CHAVE PRIVADA REMOVIDA]',
            '/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b|\b\d{11}\b/' => '[CPF REMOVIDO]',
        ];
        $saida = preg_replace(array_keys($regras), array_values($regras), $t);
        if ($saida === null) { // falha do PCRE: não grava o texto original (fail-closed)
            return ['[conteúdo removido: não foi possível verificar dados sensíveis]', true];
        }

        return [$saida, $saida !== $t];
    }

    public static function rotuloStatus(string $s): string
    {
        return ['ABERTO' => 'Aberto', 'EM_ATENDIMENTO' => 'Em atendimento', 'AGUARDANDO_SOLICITANTE' => 'Aguardando você',
            'RESOLVIDO' => 'Resolvido', 'CANCELADO' => 'Cancelado'][$s] ?? $s;
    }

    private function travarVersao(Chamado $c, int $versao): Chamado
    {
        $c = Chamado::whereKey($c->id)->lockForUpdate()->firstOrFail();
        if ($c->versao !== $versao) {
            throw new SigarbException('CONFLITO_VERSAO', 'O chamado foi alterado por outra pessoa. Recarregue antes de continuar.', 409,
                ['versao_atual' => $c->versao]);
        }

        return $c;
    }

    private function sistema(Chamado $c, Usuario $u, array $antes, array $depois): void
    {
        $partes = [];
        if ($antes['status'] !== $depois['status']) {
            $partes[] = 'situação: '.self::rotuloStatus($antes['status']).' → '.self::rotuloStatus($depois['status']);
        }
        if ($antes['prioridade'] !== $depois['prioridade']) {
            $partes[] = "prioridade: {$antes['prioridade']} → {$depois['prioridade']}";
        }
        if ($antes['atribuido_a_id'] !== $depois['atribuido_a_id']) {
            $partes[] = 'responsável: '.(Usuario::whereKey($depois['atribuido_a_id'])->value('nome') ?? 'ninguém');
        }
        ChamadoMensagem::create(['chamado_id' => $c->id, 'autor_id' => $u->id, 'tipo' => 'SISTEMA', 'corpo' => ucfirst(implode('; ', $partes)).'.']);
    }

    private function notificar(?int $usuarioId, Chamado $c, string $tipo, string $texto): void
    {
        if ($usuarioId !== null) {
            Notificacao::create(['usuario_id' => $usuarioId, 'tipo' => $tipo, 'chamado_id' => $c->id, 'texto' => Str::limit($texto, 200)]);
        }
    }

    private function protocolo(): string
    {
        // Rótulo humano, aleatório (não sequencial): não revela volume nem permite adivinhar chamados alheios.
        return 'SUP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    }
}
