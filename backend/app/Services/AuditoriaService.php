<?php

namespace App\Services;

use App\Models\EventoAuditoria;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registro append-only encadeado por hash (ADR-007). A linha única `auditoria_cadeia` serializa a escrita:
 * o lock é mantido até o commit da transação externa, impedindo bifurcação da cadeia.
 */
class AuditoriaService
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const CHAVES_PROIBIDAS = ['password', 'senha', 'senha_atual', 'token', '_token', 'remember_token', 'cookie', 'authorization'];

    public function registrar(string $acao, string $resultado = 'SUCESSO', ?string $recursoTipo = null, ?int $recursoId = null,
        ?int $unidadeId = null, array $dados = [], ?int $usuarioId = null): EventoAuditoria
    {
        $req = request();
        $evento = [
            'ocorrido_em' => now()->format('Y-m-d H:i:s.u'),
            'usuario_id' => $usuarioId ?? Auth::id(),
            'acao' => $acao,
            'resultado' => $resultado,
            'recurso_tipo' => $recursoTipo,
            'recurso_id' => $recursoId,
            'unidade_id' => $unidadeId,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
            'request_id' => $req?->attributes->get('request_id'),
            'dados' => $this->sanitizar($dados),
        ];

        return DB::transaction(function () use ($evento) {
            $anterior = DB::table('auditoria_cadeia')->where('id', 1)->lockForUpdate()->value('ultimo_hash');
            if ($anterior === null) {
                // Falha fechada: sem o ponteiro, a cadeia recomeçaria silenciosamente (adulteração mascarada).
                throw new \RuntimeException('SIGARB: ponteiro da cadeia de auditoria ausente; escrita auditada bloqueada.');
            }
            $evento['hash_anterior'] = $anterior;
            $evento['hash'] = self::calcularHash($anterior, $evento);
            $e = EventoAuditoria::create($evento);
            DB::table('auditoria_cadeia')->where('id', 1)->update(['ultimo_hash' => $evento['hash']]);

            return $e;
        });
    }

    public static function calcularHash(string $anterior, array $e): string
    {
        $canonico = json_encode([
            $e['ocorrido_em'] instanceof \DateTimeInterface ? $e['ocorrido_em']->format('Y-m-d H:i:s.u') : $e['ocorrido_em'],
            $e['usuario_id'], $e['acao'], $e['resultado'], $e['recurso_tipo'], $e['recurso_id'], $e['unidade_id'],
            self::canonico($e['dados']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $anterior.'|'.$canonico);
    }

    /** O MySQL reordena chaves de colunas JSON; o hash usa chaves ordenadas recursivamente. */
    private static function canonico(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return array_map(self::canonico(...), $v);
    }

    /**
     * Verifica a cadeia inteira. Retorna o id do primeiro evento inválido ou null.
     * ADR-015: eventos até o corte da conversão de fuso foram hasheados com `ocorrido_em` em UTC; o valor original é
     * reconstruído (hora local - deslocamento). O corte vem do próprio evento encadeado `sistema.fuso.convertido`:
     * alterá-lo quebra o hash dele.
     */
    public function verificar(): ?int
    {
        $marco = EventoAuditoria::where('acao', ConversaoFusoService::ACAO)->orderBy('id')->first();
        $corte = 0;
        $deslocamento = 0;
        if ($marco) {
            $corte = $marco->dados['corte_evento_id'] ?? null;
            $deslocamento = $marco->dados['deslocamento_segundos'] ?? null;
            if (! is_int($corte) || $deslocamento !== ConversaoFusoService::DESLOCAMENTO_SEGUNDOS || $corte >= $marco->id) {
                return $marco->id; // marco adulterado ou incoerente: falha fechada
            }
        }
        $anterior = self::GENESIS;
        foreach (EventoAuditoria::orderBy('id')->lazy(500) as $e) {
            $dados = $e->getAttributes();
            $dados['dados'] = $e->dados;
            $dados['ocorrido_em'] = $e->id <= $corte
                ? CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', self::bruto($e->getRawOriginal('ocorrido_em')), 'UTC')
                    ->subSeconds($deslocamento)->format('Y-m-d H:i:s.u')
                : $e->ocorrido_em->format('Y-m-d H:i:s.u');
            if ($e->hash_anterior !== $anterior || self::calcularHash($anterior, $dados) !== $e->hash) {
                return $e->id;
            }
            $anterior = $e->hash;
        }

        return null;
    }

    /** DATETIME(6) cru do driver, sempre com 6 casas (o PDO pode omitir a fração zerada). */
    private static function bruto(string $v): string
    {
        return str_contains($v, '.') ? str_pad($v, 26, '0') : $v.'.000000';
    }

    private function sanitizar(array $dados): ?array
    {
        if ($dados === []) {
            return null;
        }
        array_walk_recursive($dados, function (&$v, $k) {
            if (in_array(strtolower((string) $k), self::CHAVES_PROIBIDAS, true)) {
                $v = '[REMOVIDO]';
            }
        });

        return $dados;
    }
}
