<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\AcoesAuditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Descrição legível do recurso de cada evento de auditoria (só apresentação). Carrega em lote, uma consulta por tipo,
 * e respeita o escopo de quem consulta: sem acesso ao recurso, só o genérico "Tipo #id" (sem link).
 */
class RecursoAuditoriaService
{
    private const NOMES = ['usuario' => 'Usuário', 'movimentacao' => 'Movimentação', 'material' => 'Material', 'item_individual' => 'Item',
        'local_estoque' => 'Local de estoque', 'unidade' => 'Unidade', 'organizacao' => 'Organização', 'papel' => 'Papel',
        'documento' => 'Documento', 'militar' => 'Efetivo', 'chamado' => 'Chamado', 'acautelamento' => 'Acautelamento',
        'inventario' => 'Inventário', 'categorias' => 'Categoria', 'fabricantes' => 'Fabricante', 'calibres' => 'Calibre',
        'tipos-documento' => 'Tipo de documento', 'tipos-material' => 'Tipo de material', 'niveis-protecao' => 'Nível de proteção'];

    private const TABELAS_LISTA = ['categorias' => 'categorias', 'fabricantes' => 'fabricantes', 'calibres' => 'calibres',
        'tipos-documento' => 'tipos_documento', 'tipos-material' => 'tipos_material', 'niveis-protecao' => 'niveis_protecao'];

    /** @var array<string, list<int>|null> */
    private array $escopo = [];

    public function __construct(private AutorizacaoService $authz) {}

    /**
     * @param  Collection<int, object{recurso_tipo: ?string, recurso_id: ?int}>  $eventos
     * @return array<string, array{rotulo: string, link: ?string}> chave "tipo:id"
     */
    public function resolver(Usuario $quem, Collection $eventos): array
    {
        $this->escopo = [];
        $out = [];
        foreach ($eventos->filter(fn ($e) => $e->recurso_tipo && $e->recurso_id)->groupBy('recurso_tipo') as $tipo => $grupo) {
            $ids = $grupo->pluck('recurso_id')->unique()->values()->all();
            foreach ($this->carregar($quem, (string) $tipo, $ids) as $id => $r) {
                $out["$tipo:$id"] = $r;
            }
            foreach ($ids as $id) {
                $out["$tipo:$id"] ??= ['rotulo' => self::generico((string) $tipo, $id), 'link' => null];
            }
        }

        return $out;
    }

    public static function generico(string $tipo, int $id): string
    {
        return (self::NOMES[$tipo] ?? ucfirst(str_replace(['_', '-'], ' ', $tipo)))." #$id";
    }

    /** CPF no padrão ***.***.***-NN; login que não é CPF fica como está. */
    public static function mascararCpf(string $login): string
    {
        return preg_match('/^\d{11}$/', $login) ? '***.***.***-'.substr($login, -2) : $login;
    }

    /** @return array<int, array{rotulo: string, link: ?string}> */
    private function carregar(Usuario $u, string $tipo, array $ids): array
    {
        $r = fn (string $rotulo, ?string $link = null) => ['rotulo' => $rotulo, 'link' => $link];
        $linhas = fn (string $tabela, array $cols) => DB::table($tabela)->whereIn('id', $ids)->get(array_merge(['id'], $cols));
        $map = fn (Collection $c, callable $f) => $c->mapWithKeys(fn ($l) => ($v = $f($l)) ? [$l->id => $v] : [])->all();

        return match ($tipo) {
            // Quem audita com escopo global já vê nome e login dos autores; contas são de gestão global (UsuarioPolicy).
            'usuario' => ($this->authz->global($u, 'usuarios.ver') || $this->authz->global($u, 'auditoria.ver'))
                ? $map($linhas('usuarios', ['nome', 'login']), fn ($l) => $r("Usuário $l->nome (".(preg_match('/^\d{11}$/', $l->login) ? 'CPF ' : 'login ').self::mascararCpf($l->login).')'))
                : [],
            'movimentacao' => $map(DB::table('movimentacoes as m')->leftJoin('locais_estoque as o', 'o.id', '=', 'm.local_origem_id')
                ->leftJoin('locais_estoque as d', 'd.id', '=', 'm.local_destino_id')->whereIn('m.id', $ids)
                ->get(['m.id', 'm.tipo', 'o.codigo as origem', 'o.unidade_id as uo', 'd.codigo as destino', 'd.unidade_id as ud']),
                fn ($l) => ($this->ve($u, 'estoque.ver', $l->uo) || $this->ve($u, 'estoque.ver', $l->ud))
                    ? $r("Movimentação #$l->id – ".AcoesAuditoria::rotulo('estoque.'.strtolower($l->tipo)).' em '.($l->destino ?? $l->origem), "/movimentacoes/$l->id") : null),
            'material' => $this->authz->pode($u, 'catalogo.ver') || $this->authz->pode($u, 'estoque.ver')
                ? $map($linhas('materiais', ['codigo', 'nome']), fn ($l) => $r("Material $l->nome ($l->codigo)", $this->authz->pode($u, 'estoque.ver') ? "/estoque/materiais/$l->id" : null))
                : [],
            'item_individual' => $map(DB::table('itens_individuais as i')->join('materiais as m', 'm.id', '=', 'i.material_id')
                ->join('locais_estoque as le', 'le.id', '=', 'i.local_estoque_id')->whereIn('i.id', $ids)
                ->get(['i.id', 'i.identificador', 'i.numero_serie', 'i.material_id', 'm.nome', 'le.unidade_id']),
                fn ($l) => $this->ve($u, 'estoque.ver', $l->unidade_id)
                    ? $r("Item $l->nome – série ".($l->numero_serie ?: $l->identificador), "/estoque/materiais/$l->material_id") : null),
            'local_estoque' => $map($linhas('locais_estoque', ['codigo', 'nome', 'unidade_id']),
                fn ($l) => $this->ve($u, 'organizacao.ver', $l->unidade_id) || $this->ve($u, 'estoque.ver', $l->unidade_id) ? $r("Local $l->codigo – $l->nome") : null),
            'unidade' => $map($linhas('unidades', ['sigla', 'nome']), fn ($l) => $this->ve($u, 'organizacao.ver', $l->id) ? $r("Unidade $l->sigla – $l->nome") : null),
            'organizacao' => $this->authz->pode($u, 'organizacao.ver') ? $map($linhas('organizacoes', ['sigla', 'nome']), fn ($l) => $r("Organização $l->sigla – $l->nome")) : [],
            'papel' => $map($linhas('papeis', ['nome']), fn ($l) => $r("Papel $l->nome")), // nomes de papel não são sensíveis
            'documento' => $map($linhas('documentos', ['titulo', 'codigo_publico', 'unidade_id']),
                fn ($l) => $this->ve($u, 'documentos.ver', $l->unidade_id) ? $r("Documento $l->titulo", "/documentos/$l->codigo_publico") : null),
            'militar' => $map(DB::table('militares as m')->leftJoin('postos_graduacoes as p', 'p.id', '=', 'm.posto_graduacao_id')->whereIn('m.id', $ids)
                ->get(['m.id', 'm.nome_guerra', 'm.unidade_id', 'p.sigla']),
                fn ($l) => $this->ve($u, 'militares.ver', $l->unidade_id) ? $r(trim("Efetivo $l->sigla $l->nome_guerra"), "/militares/$l->id") : null),
            'chamado' => $map($linhas('chamados', ['protocolo', 'codigo', 'unidade_id']),
                fn ($l) => $this->ve($u, 'suporte.atender', $l->unidade_id) ? $r("Chamado $l->protocolo", "/suporte/chamados/$l->codigo") : null),
            'acautelamento' => $map(DB::table('acautelamentos as a')->join('militares as m', 'm.id', '=', 'a.militar_id')
                ->leftJoin('itens_individuais as i', 'i.id', '=', 'a.item_individual_id')->whereIn('a.id', $ids)
                ->get(['a.id', 'a.codigo', 'a.unidade_id', 'm.nome_guerra', 'i.identificador']),
                fn ($l) => $this->ve($u, 'estoque.ver', $l->unidade_id)
                    ? $r('Acautelamento'.($l->identificador ? " do item $l->identificador" : '')." para $l->nome_guerra", "/acautelamentos/$l->codigo") : null),
            'inventario' => $map(DB::table('inventarios as v')->join('locais_estoque as le', 'le.id', '=', 'v.local_estoque_id')->whereIn('v.id', $ids)
                ->get(['v.id', 'v.codigo', 'v.unidade_id', 'le.codigo as local']),
                fn ($l) => $this->ve($u, 'estoque.ver', $l->unidade_id) ? $r("Inventário de $l->local", "/inventarios/$l->codigo") : null),
            default => isset(self::TABELAS_LISTA[$tipo]) && $this->authz->pode($u, 'catalogo.ver')
                ? $map(DB::table(self::TABELAS_LISTA[$tipo])->whereIn('id', $ids)->get(), fn ($l) => $r(self::NOMES[$tipo].' '.(isset($l->codigo) ? "$l->codigo – " : '').$l->nome))
                : [],
        };
    }

    /** Permissão na unidade do recurso, com o escopo calculado uma vez por permissão (sem N+1). */
    private function ve(Usuario $u, string $perm, ?int $unidadeId): bool
    {
        if (! array_key_exists($perm, $this->escopo)) {
            $this->escopo[$perm] = $this->authz->unidadesPermitidas($u, $perm);
        }
        $ids = $this->escopo[$perm];

        return $ids === null || ($unidadeId !== null && in_array($unidadeId, $ids));
    }
}
