<?php

namespace Tests\Unit;

use App\Services\OperacaoEstoqueService;
use App\Services\ReferenciaService;
use App\Support\AcoesAuditoria;
use PHPUnit\Framework\TestCase;

/** Dicionário de ações de auditoria: todo código emitido pelo sistema tem rótulo explícito; desconhecidos têm rótulo automático. */
class AcoesAuditoriaTest extends TestCase
{
    /** Códigos literais em registrar('...') no código-fonte + os montados dinamicamente. */
    private function codigosDoSistema(): array
    {
        $codigos = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && preg_match_all("/registrar\(\s*'([a-z_.\-]+)'/", file_get_contents($f->getPathname()), $m)) {
                $codigos = array_merge($codigos, array_filter($m[1], fn ($c) => ! str_ends_with($c, '.')));
            }
        }
        foreach (array_merge(['ENTRADA', 'ESTORNO'], array_keys(OperacaoEstoqueService::ABERTURAS), array_keys(OperacaoEstoqueService::CONCLUSOES)) as $t) {
            $codigos[] = 'estoque.'.strtolower($t);
        }
        foreach (array_keys(ReferenciaService::LISTAS) as $l) {
            foreach (['criada', 'atualizada', 'desativada', 'reativada'] as $e) {
                $codigos[] = "referencia.$l.$e";
            }
        }
        foreach (['solicitado', 'aprovado', 'rejeitado', 'cancelado', 'entregue', 'inspecionado', 'encerrado'] as $e) {
            $codigos[] = "acautelamento.$e";
        }
        foreach (['aberto', 'contagem_salva', 'contagem_concluida', 'cancelado', 'ajuste_aprovado', 'ajuste_rejeitado'] as $e) {
            $codigos[] = "inventario.$e";
        }

        return array_values(array_unique(array_merge($codigos, ['importacao.simulada', 'importacao.executada', 'documento.arquivado', 'documento.reativado',
            'suporte.nota_interna', 'suporte.resposta', 'catalogo.categoria_removida', 'usuario.papel_atribuido', 'usuario.papel_removido', 'usuario.senha_redefinida'])));
    }

    public function test_todos_os_codigos_do_sistema_tem_rotulo_explicito(): void
    {
        $codigos = $this->codigosDoSistema();
        $this->assertGreaterThan(80, count($codigos));
        $sem = array_values(array_filter($codigos, fn ($c) => ! AcoesAuditoria::conhecida($c)));
        $this->assertSame([], $sem, 'Ações sem rótulo em App\Support\AcoesAuditoria: '.implode(', ', $sem));
    }

    public function test_rotulos_e_frases_em_portugues(): void
    {
        $this->assertSame('Consulta à auditoria', AcoesAuditoria::rotulo('auditoria.consulta'));
        $this->assertSame('Categoria criada', AcoesAuditoria::rotulo('referencia.categorias.criada'));
        $this->assertSame('Nível de proteção desativado', AcoesAuditoria::rotulo('referencia.niveis-protecao.desativada'));
        $this->assertSame('José Filho registrou a entrada: Movimentação #1182', AcoesAuditoria::frase('estoque.entrada', 'José Filho', 'Movimentação #1182'));
        $this->assertSame('Sistema entrou no sistema', AcoesAuditoria::frase('auth.login', 'Sistema', null));
    }

    public function test_codigo_desconhecido_recebe_rotulo_automatico_legivel(): void
    {
        $this->assertFalse(AcoesAuditoria::conhecida('usuario.foto_trocada'));
        $this->assertSame('Usuário – foto trocada', AcoesAuditoria::rotulo('usuario.foto_trocada'));
        $this->assertSame('Auditoria – consulta', AcoesAuditoria::automatico('auditoria.consulta'));
        $this->assertSame('Ana realizou a ação “Usuário – foto trocada”', AcoesAuditoria::frase('usuario.foto_trocada', 'Ana', null));
        $this->assertSame('Ação', AcoesAuditoria::rotulo(''));
    }
}
