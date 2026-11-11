<?php

use App\Models\Organizacao;
use App\Models\Unidade;
use Illuminate\Database\Migrations\Migration;

/**
 * PRB como primeiro nível da árvore e seções do Sistema de Escalas.
 *
 * - Cria a unidade raiz "PRB" e coloca os perímetros (criados em 2026_11_17_000200, até aqui sem superior) abaixo dela.
 * - Cria as seções cadastradas no Sistema de Escalas (escalas.setores, todas em Maceió › Poço) como subunidades da base Poço.
 *   Lista copiada do Escalas em 2026-11-11; novas seções entram pela tela Organização.
 * Só estrutura (nenhuma pessoa). Idempotente: não duplica nem renomeia o que já existe.
 */
return new class extends Migration
{
    /** sigla curta => nome, como no Escalas. */
    public const SECOES_POCO = [
        'GERENCIA' => 'GERÊNCIA DO PROGRAMA RONDA DO BAIRRO',
        'COORDENACAO' => 'SEÇÃO COORDENAÇÃO',
        'P1' => 'P1 - SEÇÃO DE PESSOAL',
        'P3' => 'P3 - SEÇÃO DE PLANEJAMENTO, PROJETOS E INSTRUÇÃO',
        'P4' => 'P4 - SEÇÃO DE CONTROLE DE MATERIAL E PATRIMÔNIO',
        'RH' => 'RH - SEÇÃO DE RECURSOS HUMANOS',
        'TI' => 'TI - SEÇÃO DE TECNOLOGIA DE INFORMAÇÃO',
        'SMB' => 'SMB - SEÇÃO DE MATERIAIS BÉLICOS',
        'TELE' => 'TELE - SEÇÃO COORDENAÇÃO OPERACIONAL DE TELEATENDIMENTO E VIDEOMONITORAMENTO',
        'SOCIAL' => 'SOCIAL - SEÇÃO DE ARTICULAÇÃO E MOBILIZAÇÃO SOCIAL',
        'DISC' => 'DISC - SEÇÃO DE DISCIPLINA E OUVIDORIA',
        'FROTA' => 'SEÇÃO DE FROTA',
        'LOGISTICA' => 'SEÇÃO DE LOGÍSTICA',
        'BANDA' => 'BANDA - SEÇÃO DE MÚSICA',
    ];

    public function up(): void
    {
        $org = Organizacao::firstOrCreate(['sigla' => 'PRB'], ['nome' => 'Programa Ronda no Bairro']);
        $raiz = Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => 'PRB'],
            ['nome' => 'Programa Ronda no Bairro', 'tipo' => 'UNIDADE', 'unidade_superior_id' => null]);

        $raiz->refresh();

        // Toda unidade sem superior (os perímetros) passa a ficar abaixo do PRB.
        Unidade::where('organizacao_id', $org->id)->whereNull('unidade_superior_id')->whereKeyNot($raiz->id)
            ->update(['unidade_superior_id' => $raiz->id, 'tipo' => 'SUBUNIDADE']);

        // "caminho" (escopo de acesso) é gravado só na criação: prefixar com o caminho do PRB
        // o de toda unidade da organização que ainda não está abaixo dele (perímetros e descendentes).
        Unidade::where('organizacao_id', $org->id)->whereKeyNot($raiz->id)
            ->where('caminho', 'not like', $raiz->caminho.'%')
            ->each(fn (Unidade $u) => $u->forceFill(['caminho' => $raiz->caminho.ltrim($u->caminho, '/')])->saveQuietly());

        $poco =Unidade::where('organizacao_id', $org->id)->where('sigla', 'MACEIO-POCO')->first();
        if (! $poco) {
            return; // estrutura base ausente: as seções ficam para a tela
        }
        foreach (self::SECOES_POCO as $sigla => $nome) {
            Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => 'POCO-'.$sigla],
                ['nome' => $nome, 'tipo' => 'SUBUNIDADE', 'unidade_superior_id' => $poco->id]);
        }
    }

    public function down(): void
    {
        // Não remove: unidades podem já ter efetivo, locais e movimentações (restrictOnDelete).
    }
};
