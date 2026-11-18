<?php

namespace App\Services;

use App\Models\Militar;
use App\Models\Notificacao;
use App\Models\Unidade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Decisão do responsável (2026-11-18): efetivo que passa a RR/PM_ATIVA/BM_ATIVA com posto CIV precisa ter o posto/graduação
 * atualizado. Avisa quem tem `militares.gerir` ou `usuarios.gerir` na unidade do efetivo (ou acima / global), sem duplicar:
 * quem já tem aviso não lido do mesmo efetivo não recebe outro. Cada envio é auditado (só ids e contagem).
 */
class AvisoPostoEfetivoService
{
    public const TIPO = 'POSTO_A_ATUALIZAR';

    public const PERMISSOES = ['militares.gerir', 'usuarios.gerir'];

    public function __construct(private AuditoriaService $auditoria) {}

    /** Chamar depois de gravar a categoria. Devolve quantos avisos novos foram criados. */
    public function avaliar(Militar $m, ?string $categoriaAntes): int
    {
        $m->loadMissing('postoGraduacao');
        if ($categoriaAntes === $m->categoria_efetivo || ! Militar::postoAAtualizar($m->postoGraduacao?->sigla, $m->categoria_efetivo)) {
            return 0;
        }
        $jaAvisados = Notificacao::where('tipo', self::TIPO)->where('militar_id', $m->id)->whereNull('lida_em')->pluck('usuario_id')->all();
        $destino = array_values(array_diff($this->destinatarios((int) $m->unidade_id), $jaAvisados));
        $categoria = Militar::CATEGORIAS[$m->categoria_efetivo] ?? $m->categoria_efetivo;
        $texto = Str::limit("Atualize o posto/graduação de {$m->nome} (agora {$categoria}).", 200);
        foreach ($destino as $id) {
            Notificacao::create(['usuario_id' => $id, 'tipo' => self::TIPO, 'militar_id' => $m->id, 'texto' => $texto]);
        }
        if ($destino) {
            $this->auditoria->registrar('militar.aviso_posto', 'SUCESSO', 'militar', $m->id, $m->unidade_id,
                ['categoria_antes' => $categoriaAntes, 'categoria' => $m->categoria_efetivo, 'destinatarios' => count($destino)]);
        }

        return count($destino);
    }

    /** @return list<int> contas ativas com a permissão na unidade (atribuição global ou em unidade ancestral); delegação vencida não conta. */
    private function destinatarios(int $unidadeId): array
    {
        $caminho = Unidade::whereKey($unidadeId)->value('caminho');

        return DB::table('atribuicoes_papel as a')
            ->join('papel_permissao as pp', 'pp.papel_id', '=', 'a.papel_id')->join('permissoes as pe', 'pe.id', '=', 'pp.permissao_id')
            ->join('usuarios as us', 'us.id', '=', 'a.usuario_id')->leftJoin('unidades as un', 'un.id', '=', 'a.unidade_id')
            ->whereIn('pe.codigo', self::PERMISSOES)->where('us.ativo', true)
            ->where(fn ($q) => $q->whereNull('a.expira_em')->orWhere('a.expira_em', '>', now()))
            ->get(['a.usuario_id', 'a.unidade_id', 'un.caminho'])
            ->filter(fn ($l) => $l->unidade_id === null || ($caminho !== null && str_starts_with($caminho, $l->caminho)))
            ->pluck('usuario_id')->map(fn ($id) => (int) $id)->unique()->take(100)->values()->all();
    }
}
