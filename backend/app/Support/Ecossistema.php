<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Sobre o sistema" a partir do banco compartilhado `ecossistema` (deploy/modelos/ecossistema.sql).
 * - Versão: arquivo backend/VERSION da release (+ git_sha do MANIFESTO.txt). Registrada em `versoes` na primeira
 *   consulta depois do deploy (INSERT IGNORE): o histórico mostra quando cada versão entrou no ar.
 * - Equipe: participações atuais (fim nulo ou futuro) e "Já fizeram parte" (fim passado). Ninguém é apagado.
 * - Banco indisponível: devolve só a versão do arquivo; a tela usa o conteúdo fixo.
 */
final class Ecossistema
{
    public const SISTEMA = 'ARSENAL';

    public static function sobre(): array
    {
        $versao = self::versaoArquivo();
        $sha = self::gitSha();
        try {
            return Cache::remember('ecossistema.sobre.'.$versao.$sha, 300, function () use ($versao, $sha) {
                $db = DB::connection('ecossistema');
                $sistema = $db->table('sistemas')->where('codigo', self::SISTEMA)->first();
                if (! $sistema) {
                    return self::semBanco($versao, $sha);
                }
                if ($versao) {
                    $db->table('versoes')->insertOrIgnore(['sistema_id' => $sistema->id, 'versao' => $versao, 'git_sha' => $sha ?? '']);
                }
                $historico = $db->table('versoes')->where('sistema_id', $sistema->id)->orderByDesc('implantado_em')->orderByDesc('id')->limit(10)
                    ->get(['versao', 'implantado_em'])->map(fn ($v) => ['versao' => $v->versao, 'implantado_em' => $v->implantado_em ? CarbonImmutable::parse($v->implantado_em, config('app.timezone'))->toIso8601String() : null])->all();
                $hoje = now()->toDateString();
                $part = $db->table('participacoes as pa')->join('pessoas as p', 'p.id', '=', 'pa.pessoa_id')
                    ->where(fn ($q) => $q->whereNull('pa.sistema_id')->orWhere('pa.sistema_id', $sistema->id))
                    ->orderBy('pa.grupo')->orderBy('pa.ordem')->orderBy('p.nome')
                    ->get(['p.nome', 'pa.grupo', 'pa.cargo', 'pa.atribuicao', 'pa.inicio', 'pa.fim']);
                $atual = fn ($x) => $x->fim === null || $x->fim > $hoje;
                $pessoa = fn ($x) => ['nome' => $x->nome, 'cargo' => $x->cargo, 'atribuicao' => $x->atribuicao, 'inicio' => $x->inicio, 'fim' => $x->fim];

                return [
                    'sistema' => $sistema->nome, 'versao' => $versao, 'git_sha' => $sha ? substr($sha, 0, 7) : null,
                    'implantado_em' => collect($historico)->firstWhere('versao', $versao)['implantado_em'] ?? null,
                    'historico' => $historico,
                    'governanca' => $part->filter(fn ($x) => $x->grupo === 'GOVERNANCA' && $atual($x))->map($pessoa)->values()->all(),
                    'equipe' => $part->filter(fn ($x) => $x->grupo === 'DESENVOLVIMENTO' && $atual($x))->map($pessoa)->values()->all(),
                    'ex_integrantes' => $part->reject($atual)->map($pessoa)->values()->all(),
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('ecossistema indisponível', ['erro' => $e->getMessage()]);

            return self::semBanco($versao, $sha);
        }
    }

    private static function semBanco(?string $versao, ?string $sha): array
    {
        return ['sistema' => null, 'versao' => $versao, 'git_sha' => $sha ? substr($sha, 0, 7) : null, 'implantado_em' => null,
            'historico' => [], 'governanca' => null, 'equipe' => null, 'ex_integrantes' => []];
    }

    private static function versaoArquivo(): ?string
    {
        $v = @file_get_contents(base_path('VERSION'));

        return $v !== false && preg_match('/^\d+\.\d+\.\d+$/', trim($v)) ? trim($v) : null;
    }

    private static function gitSha(): ?string
    {
        $m = @file_get_contents(base_path('../MANIFESTO.txt'));

        return $m !== false && preg_match('/^git_sha=([0-9a-f]{40})$/m', $m, $x) ? $x[1] : null;
    }
}
