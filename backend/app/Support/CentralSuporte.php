<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chamados e avisos da Central de Suporte PRB do usuário logado (Central-Suporte/docs/INTEGRACAO.md §3, contrato v1).
 *
 * - Filtra SEMPRE pelo CPF recebido do controller, que vem da conta autenticada (`usuarios.login`), nunca da
 *   requisição. CPF inválido (ex.: conta de serviço) = nada.
 * - Desligável: sem SUPORTE_DB_DATABASE não abre conexão; banco/view ausente ou fora do ar = `disponivel: false`
 *   com listas vazias (log de aviso sem CPF, nunca 5xx). O resultado (inclusive a falha) fica 60 s em cache por
 *   usuário, com chave = sha256(CPF + APP_KEY) — o CPF não aparece em claro no cache.
 * - Links montados aqui a partir de SUPORTE_URL + `codigo` (26 alfanuméricos); o `link` da view não é repassado.
 * - Só leitura: não grava nada (nem auditoria — leitura dos próprios dados, como GET /notifications). Os avisos
 *   saem da view quando a pessoa os abre NA Central.
 */
final class CentralSuporte
{
    private const LIMITE_CHAMADOS = 10;

    private const LIMITE_AVISOS = 10;

    /** @return array<string, mixed> */
    public static function doUsuario(?string $login): array
    {
        $base = self::vazio();
        $cpf = Cpf::digitos($login);
        if (config('central_suporte.banco') === '' || ! Cpf::valido($cpf)) {
            return $base;
        }

        $chave = 'central_suporte.'.hash('sha256', $cpf.'|'.config('app.key'));

        return Cache::remember($chave, (int) config('central_suporte.cache', 60), function () use ($cpf, $base) {
            try {
                $db = DB::connection('suporte');
                $chamados = $db->table('v_chamados_solicitante')->where('cpf', $cpf)
                    ->orderByDesc('aberto')->orderByDesc('aguardando_solicitante')->orderByDesc('atualizado_em')
                    ->limit(self::LIMITE_CHAMADOS)
                    ->get(['codigo', 'protocolo', 'titulo', 'sistema', 'status', 'status_rotulo', 'prioridade', 'aberto', 'aguardando_solicitante', 'criado_em', 'atualizado_em']);
                $abertos = $db->table('v_chamados_solicitante')->where('cpf', $cpf)->where('aberto', 1)->count();
                $avisos = $db->table('v_notificacoes_solicitante')->where('cpf', $cpf)
                    ->orderByDesc('criada_em')->orderByDesc('id')->limit(self::LIMITE_AVISOS)
                    ->get(['id', 'codigo', 'protocolo', 'tipo', 'texto', 'criada_em']);
                $naoLidos = $db->table('v_notificacoes_solicitante')->where('cpf', $cpf)->count();
            } catch (\Throwable $e) {
                Log::warning('central de suporte indisponível', ['erro' => $e::class]);

                return $base;
            }

            return ['disponivel' => true, 'abertos' => $abertos, 'nao_lidos' => $naoLidos,
                'chamados' => $chamados->map(fn ($c) => [
                    'protocolo' => self::texto($c->protocolo, 30),
                    'titulo' => self::texto($c->titulo, 150),
                    'sistema' => self::texto($c->sistema, 30),
                    'status' => self::texto($c->status, 30),
                    'status_rotulo' => self::texto($c->status_rotulo, 60),
                    'prioridade' => self::texto($c->prioridade, 20),
                    'aberto' => (int) $c->aberto === 1,
                    'aguardando_voce' => (int) $c->aguardando_solicitante === 1,
                    'criado_em' => $c->criado_em === null ? null : (string) $c->criado_em,
                    'atualizado_em' => $c->atualizado_em === null ? null : (string) $c->atualizado_em,
                    'link' => self::linkChamado($c->codigo),
                ])->all(),
                'avisos' => $avisos->map(fn ($a) => [
                    'id' => (int) $a->id,
                    'protocolo' => self::texto($a->protocolo, 30),
                    'tipo' => self::texto($a->tipo, 40),
                    'texto' => self::texto($a->texto, 300),
                    'criada_em' => $a->criada_em === null ? null : (string) $a->criada_em,
                    'link' => self::linkChamado($a->codigo),
                ])->all(),
            ] + $base;
        });
    }

    /**
     * Resposta sem leitura (desligada, CPF inválido ou banco indisponível).
     *
     * @return array<string, mixed>
     */
    private static function vazio(): array
    {
        $url = self::url();

        return [
            'disponivel' => false, 'abertos' => 0, 'nao_lidos' => 0, 'chamados' => [], 'avisos' => [],
            'url_abrir' => $url ? $url.'/novo?sistema='.rawurlencode((string) config('central_suporte.sistema')) : null,
            'url_chamados' => $url ? $url.'/chamados' : null,
        ];
    }

    private static function url(): ?string
    {
        $u = (string) config('central_suporte.url');

        return preg_match('#^https?://[^\s"<>\'`]+$#i', $u) ? $u : null;
    }

    private static function linkChamado(mixed $codigo): ?string
    {
        $url = self::url();
        if ($url === null) {
            return null;
        }

        return is_string($codigo) && preg_match('/^[0-9A-Za-z]{26}$/', $codigo) ? $url.'/chamados/'.$codigo : $url.'/chamados';
    }

    private static function texto(mixed $v, int $max): string
    {
        return mb_substr(trim((string) $v), 0, $max);
    }
}
