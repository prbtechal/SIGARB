<?php

namespace App\Services;

use App\Models\Militar;
use App\Models\PostoGraduacao;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\Cpf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Efetivo a partir do cadastro de funcionários (banco `funcionarios`, fonte da verdade do Sistema de Escalas) e vínculo das contas.
 *
 * Campos que vêm da origem e são sobrescritos: nome, matricula, cpf, funcao (cargo), situacao, unidade_id e categoria_efetivo
 * (esta só quando a origem informa). Os demais (nome de guerra, posto, quadro, contatos, observações, foto) são locais.
 * Nada é apagado: quem sai da origem vira INATIVO.
 *
 * Unidade: perímetro → base → unidade da origem, pela mesma sigla que a migração 2026_11_17_000200 gerou
 * ("MACEIO", "MACEIO-POCO", "MACEIO-POCO-ORLA"); usa o nível mais fundo encontrado, senão a raiz PRB.
 * Seções (setor_id → escalas.setores) não são lidas: exigiriam acesso a outro banco. Quem está numa seção fica na base.
 */
class EfetivoFuncionariosService
{
    public const OBS_LOCAL = 'Criado a partir do usuário — não consta no cadastro de funcionários';

    public function __construct(private AuditoriaService $auditoria) {}

    public static function configurado(): bool
    {
        return (string) config('database.connections.funcionarios.database') !== '';
    }

    /** @return array{linhas: list<string>, contagem: array<string,int>} */
    public function sincronizar(bool $simular, bool $forcar = false): array
    {
        // Roda a cada minuto: sem mudança na origem (quantidade + última alteração), não lê nem grava nada.
        $marca = json_encode(DB::connection('funcionarios')->table('funcionarios')->selectRaw('COUNT(*) as n, MAX(atualizado_em) as ult')->first());
        if (! $forcar && ! $simular && Cache::get('efetivo.sincronizacao.marca') === $marca) {
            return ['linhas' => [], 'contagem' => ['sem_mudanca_na_origem' => 1]];
        }
        $origem = DB::connection('funcionarios')->table('funcionarios as f')
            ->leftJoin('perimetros as p', 'p.id', '=', 'f.perimetro_id')
            ->leftJoin('bases as b', 'b.id', '=', 'f.base_id')
            ->leftJoin('unidades as u', 'u.id', '=', 'f.unidade_id')
            ->orderBy('f.id')
            ->get(['f.id', 'f.matricula', 'f.nome', 'f.cpf', 'f.cargo', 'f.categoria', 'f.ativo', 'f.situacao',
                'p.nome as perimetro', 'b.nome as base', 'u.nome as unidade']);

        return $this->emTransacao($simular, function () use ($origem, $marca) {
            $c = ['criados' => 0, 'atualizados' => 0, 'sem_mudanca' => 0, 'inativados' => 0];
            $linhas = [];
            $civ = PostoGraduacao::firstOrCreate(['sigla' => 'CIV'], ['nome' => 'Civil / sem posto', 'ordem' => 0])->id;
            $vistos = [];
            foreach ($origem as $f) {
                $vistos[] = $f->id;
                $m = Militar::where('funcionario_id', $f->id)->first();
                $d = $this->campos($f, $m);
                if (! $m) {
                    $m = Militar::create($d + ['funcionario_id' => $f->id, 'posto_graduacao_id' => $civ, 'nome_guerra' => self::nomeGuerra($d['nome'])]);
                    $this->auditoria->registrar('militar.sincronizado', 'SUCESSO', 'militar', $m->id, $m->unidade_id,
                        ['origem' => 'funcionarios', 'funcionario_id' => $f->id, 'operacao' => 'criado']);
                    $c['criados']++;
                    $linhas[] = "+ #{$f->id} {$d['nome']}";

                    continue;
                }
                $m->fill($d);
                if (! $m->isDirty()) {
                    $c['sem_mudanca']++;

                    continue;
                }
                $mudou = array_keys($m->getDirty());
                $antes = array_intersect_key($m->getOriginal(), array_flip($mudou));
                $m->save();
                $this->cascataConta($m, $antes['situacao'] ?? null);
                // CPF não vai em claro para o registro permanente.
                $this->auditoria->registrar('militar.sincronizado', 'SUCESSO', 'militar', $m->id, $m->unidade_id,
                    ['origem' => 'funcionarios', 'funcionario_id' => $f->id, 'operacao' => 'atualizado',
                        'antes' => self::semCpf($antes), 'depois' => self::semCpf($m->only($mudou))]);
                $c['atualizados']++;
                $linhas[] = "~ #{$f->id} {$d['nome']}: ".implode(', ', $mudou);
            }
            $sairam = Militar::whereNotNull('funcionario_id')->whereNotIn('funcionario_id', $vistos ?: [0])->where('situacao', '!=', 'INATIVO')->get();
            foreach ($sairam as $m) {
                $antesSit = $m->situacao;
                $m->update(['situacao' => 'INATIVO']);
                $this->cascataConta($m, $antesSit);
                $this->auditoria->registrar('militar.sincronizado', 'SUCESSO', 'militar', $m->id, $m->unidade_id,
                    ['origem' => 'funcionarios', 'funcionario_id' => $m->funcionario_id, 'operacao' => 'inativado_ausente_na_origem']);
                $c['inativados']++;
                $linhas[] = "- #{$m->funcionario_id} {$m->nome}: ausente na origem → INATIVO";
            }
            if ($c['criados'] + $c['atualizados'] + $c['inativados'] > 0) {
                $this->auditoria->registrar('efetivo.sincronizacao', 'SUCESSO', dados: $c + ['lidos' => count($vistos)]);
            }
            DB::afterCommit(fn () => Cache::forever('efetivo.sincronizacao.marca', $marca));

            return ['linhas' => $linhas, 'contagem' => $c];
        });
    }

    /** Liga cada conta sem efetivo: pelo CPF (login) ou criando um efetivo local com os dados da conta. */
    public function vincularUsuarios(bool $simular): array
    {
        return $this->emTransacao($simular, function () {
            $c = ['vinculados_cpf' => 0, 'criados_locais' => 0];
            $linhas = [];
            foreach (Usuario::whereNull('militar_id')->with('atribuicoes')->orderBy('id')->get() as $u) {
                $cpf = Cpf::valido((string) $u->login) ? $u->login : null;
                $m = $cpf ? Militar::where('cpf', $cpf)->whereDoesntHave('usuario')->first() : null;
                if ($m) {
                    $c['vinculados_cpf']++;
                    $linhas[] = "= conta #{$u->id} {$u->nome} → efetivo #{$m->id} {$m->nome} (CPF)";
                } else {
                    $m = Militar::create([
                        'nome' => mb_substr($u->nome, 0, 160), 'nome_guerra' => self::nomeGuerra($u->nome),
                        'matricula' => self::livre('matricula', $u->matricula) ?? 'USR-'.$u->id,
                        'cpf' => $cpf && ! Militar::where('cpf', $cpf)->exists() ? $cpf : null,
                        'funcao' => $u->cargo ? mb_substr($u->cargo, 0, 80) : null,
                        'posto_graduacao_id' => $u->posto_graduacao_id ?? PostoGraduacao::firstOrCreate(['sigla' => 'CIV'], ['nome' => 'Civil / sem posto', 'ordem' => 0])->id,
                        'unidade_id' => $u->atribuicoes->sortBy('id')->first()?->unidade_id ?? self::raiz(),
                        'observacoes_administrativas' => self::OBS_LOCAL,
                    ]);
                    $this->auditoria->registrar('militar.criado', 'SUCESSO', 'militar', $m->id, $m->unidade_id, ['origem' => 'usuario', 'usuario_id' => $u->id]);
                    $c['criados_locais']++;
                    $linhas[] = "+ conta #{$u->id} {$u->nome} → efetivo local #{$m->id}";
                }
                $u->forceFill(['militar_id' => $m->id])->saveQuietly();
                $this->auditoria->registrar('usuario.efetivo_vinculado', 'SUCESSO', 'usuario', $u->id, dados: ['militar_id' => $m->id, 'automatico' => true]);
            }

            return ['linhas' => $linhas, 'contagem' => $c];
        });
    }

    /**
     * Efetivo da origem que passou a INATIVO: a conta ligada é desativada (sessões e dispositivos revogados).
     * Volta a ativo na origem: a conta NÃO é reativada sozinha — só registra para o administrador decidir.
     */
    private function cascataConta(Militar $m, ?string $antes): void
    {
        $u = Usuario::where('militar_id', $m->id)->first();
        if (! $u || ! $m->funcionario_id || $antes === null || $antes === $m->situacao) {
            return;
        }
        if ($m->situacao === 'INATIVO' && $u->ativo) {
            $u->forceFill(['ativo' => false, 'desativado_em' => now()])->saveQuietly();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            app(DispositivoService::class)->revogarTodos($u->id);
            $this->auditoria->registrar('usuario.desativado_pela_origem', 'SUCESSO', 'usuario', $u->id, $m->unidade_id,
                ['militar_id' => $m->id, 'funcionario_id' => $m->funcionario_id, 'motivo' => 'Inativo ou desligado no cadastro de funcionários']);
        } elseif ($antes === 'INATIVO' && ! $u->ativo) {
            $this->auditoria->registrar('usuario.reativacao_pendente_origem', 'SUCESSO', 'usuario', $u->id, $m->unidade_id,
                ['militar_id' => $m->id, 'funcionario_id' => $m->funcionario_id, 'motivo' => 'Voltou a ativo no cadastro de funcionários; reativar a conta é decisão do administrador']);
        }
    }

    private function campos(object $f, ?Militar $atual): array
    {
        $situacao = ! $f->ativo || $f->situacao === 'desligado' ? 'INATIVO' : ($f->situacao === 'standby' ? 'AFASTADO' : 'ATIVO');
        $cpf = Cpf::valido((string) $f->cpf) ? $f->cpf : null;
        $mat = strtoupper(trim((string) $f->matricula));
        $d = [
            'nome' => mb_substr(trim($f->nome), 0, 160),
            // Matrícula vazia, fora do formato ou já usada por outro efetivo: identificador sintético estável.
            'matricula' => preg_match('/^[A-Z0-9-]{4,30}$/', $mat) && self::livre('matricula', $mat, $atual?->id) ? $mat : 'FUNC-'.$f->id,
            'cpf' => $cpf && self::livre('cpf', $cpf, $atual?->id) ? $cpf : null,
            'funcao' => $f->cargo ? mb_substr($f->cargo, 0, 80) : null,
            'situacao' => $situacao,
            'unidade_id' => self::unidade($f->perimetro, $f->base, $f->unidade),
        ];
        if ($f->categoria) {
            $d['categoria_efetivo'] = strtoupper($f->categoria);
        }

        return $d;
    }

    private static function livre(string $campo, ?string $valor, ?int $ignorar = null): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Militar::where($campo, $valor)->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar))->exists() ? null : $valor;
    }

    private static array $cacheUnidades = [];

    private static function unidade(?string $perimetro, ?string $base, ?string $unidade): int
    {
        $siglas = [];
        $acc = '';
        foreach ([$perimetro, $base, $unidade] as $nome) {
            if (! $nome) {
                break;
            }
            $acc = ltrim($acc.'-'.trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($nome)), '-'), '-');
            $siglas[] = substr(strtoupper($acc), 0, 30);
        }
        foreach (array_reverse($siglas) as $s) {
            $id = self::$cacheUnidades[$s] ??= (int) Unidade::where('sigla', $s)->value('id');
            if ($id) {
                return $id;
            }
        }

        return self::raiz();
    }

    private static function raiz(): int
    {
        return (int) Unidade::where('sigla', 'PRB')->whereNull('unidade_superior_id')->value('id')
            ?: (int) Unidade::whereNull('unidade_superior_id')->orderBy('id')->value('id');
    }

    private static function nomeGuerra(string $nome): string
    {
        $p = preg_split('/\s+/', trim($nome));

        return mb_substr(count($p) > 1 ? $p[0].' '.end($p) : $p[0], 0, 60);
    }

    private static function semCpf(array $d): array
    {
        if (array_key_exists('cpf', $d)) {
            $d['cpf'] = $d['cpf'] ? '[informado]' : null;
        }

        return $d;
    }

    private function emTransacao(bool $simular, callable $fn): array
    {
        self::$cacheUnidades = [];
        DB::beginTransaction();
        try {
            $r = $fn();
            $simular ? DB::rollBack() : DB::commit();

            return $r;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
