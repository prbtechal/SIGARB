<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * RNF-09 — liveness (processo vivo, sem dependências) e readiness (dependências essenciais).
 * Respostas públicas mínimas: apenas "ok"/"falha" por verificação, sem hosts, nomes de banco, caminhos ou mensagens.
 * Readiness tem cache de 5 s e limite de taxa na rota, para não virar vetor de DoS.
 */
class SaudeController extends Controller
{
    public function vivo(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function pronto(): JsonResponse
    {
        $r = Cache::remember('sigarb:saude:pronto', 5, fn () => $this->verificar());
        $ok = ! in_array('falha', $r, true);

        return response()->json(['status' => $ok ? 'ok' : 'indisponivel', 'verificacoes' => $r], $ok ? 200 : 503);
    }

    private function verificar(): array
    {
        return [
            'banco' => $this->tentar('banco', fn () => DB::select('SELECT 1') && DB::table('auditoria_cadeia')->where('id', 1)->exists()),
            'armazenamento_documentos' => $this->tentar('armazenamento', function () {
                $d = Storage::disk('documentos');
                $nome = 'saude/.sonda-'.bin2hex(random_bytes(6));
                $d->put($nome, 'ok');
                $lido = $d->get($nome) === 'ok';
                $d->delete($nome);

                return $lido;
            }),
            'espaco_em_disco' => $this->tentar('disco', function () {
                $livre = @disk_free_space(Storage::disk('documentos')->path(''));

                return $livre !== false && $livre >= (int) config('sigarb.saude.disco_minimo_bytes');
            }),
        ];
    }

    private function tentar(string $nome, callable $f): string
    {
        try {
            return $f() ? 'ok' : 'falha';
        } catch (Throwable $e) {
            Log::channel('sigarb_json')->error('saude.falha', ['verificacao' => $nome, 'excecao' => $e::class]); // detalhe só no log interno

            return 'falha';
        }
    }
}
