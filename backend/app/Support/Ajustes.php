<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CP-16 — ajustes editáveis pela tela (só Administrador). Cada chave é um caminho dentro de config('sigarb').
 * O arquivo continua sendo o padrão; o banco guarda só o que foi mudado. Desligar operação só BLOQUEIA (ADR-006):
 * nada aqui libera operação que o arquivo mantém bloqueada.
 */
class Ajustes
{
    public const PAPEIS_AUTOAPROVACAO = ['ARMEIRO', 'GESTOR', 'APROVADOR'];

    /** chave => [grupo, rotulo, descricao, tipo, min, max]. */
    public static function catalogo(): array
    {
        $c = [];
        foreach (config('sigarb.operacoes') as $op => $o) {
            $c["operacoes.$op.desligada"] = ['grupo' => 'Operações', 'rotulo' => $o['rotulo'],
                'descricao' => $o['regra_provisoria'] ?? ($op === 'ENTRADA' ? 'Registro de entrada de material no estoque.' : 'Desfaz uma entrada registrada por engano.'), 'tipo' => 'operacao'];
        }

        return $c + [
            'politicas.autoaprovacao_papeis' => ['grupo' => 'Aprovações', 'rotulo' => 'Quem pode aprovar o próprio pedido',
                'descricao' => 'Papéis que podem pedir e aprovar o próprio acautelamento ou ajuste de inventário. Fica registrado na auditoria.', 'tipo' => 'papeis'],
            'politicas.bloquear_vencido' => ['grupo' => 'Aprovações', 'rotulo' => 'Bloquear entrega de item vencido',
                'descricao' => 'Item ou lote com validade anterior a hoje não pode ser entregue nem reservado.', 'tipo' => 'bool'],
            'alertas.validade_dias' => ['grupo' => 'Prazos e alertas', 'rotulo' => 'Alerta de lote vencendo (dias antes)', 'descricao' => 'Antecedência do alerta de validade de lotes.', 'tipo' => 'int', 'min' => 1, 'max' => 730],
            'alertas.validade_item_dias' => ['grupo' => 'Prazos e alertas', 'rotulo' => 'Alerta de colete/item vencendo (dias antes)', 'descricao' => 'Antecedência do alerta de validade de itens individuais.', 'tipo' => 'int', 'min' => 1, 'max' => 730],
            'acautelamento.inspecao_dias' => ['grupo' => 'Prazos e alertas', 'rotulo' => 'Intervalo entre inspeções (dias)', 'descricao' => 'Contado da entrega ou da última inspeção.', 'tipo' => 'int', 'min' => 30, 'max' => 1825],
            'acautelamento.alerta_inspecao_dias' => ['grupo' => 'Prazos e alertas', 'rotulo' => 'Alerta de inspeção (dias antes)', 'descricao' => 'Antecedência do aviso de inspeção vencendo.', 'tipo' => 'int', 'min' => 1, 'max' => 365],
            'sessao.max_simultaneas' => ['grupo' => 'Acesso', 'rotulo' => 'Sessões abertas por usuário', 'descricao' => 'Ao passar do limite, a sessão mais antiga é encerrada.', 'tipo' => 'int', 'min' => 1, 'max' => 10],
        ];
    }

    /**
     * Aplica os valores salvos sobre config('sigarb'). Chamado a cada requisição (middleware).
     * O padrão de cada chave mexida fica no próprio repositório de config (por instância da aplicação, não estático).
     */
    public static function aplicar(): void
    {
        try {
            $linhas = DB::table('configuracoes')->whereNotNull('valor')->pluck('valor', 'chave')->all();
        } catch (QueryException) {
            return; // shortcut: banco sem a migração CP-16 roda só com o arquivo; some depois do migrate
        }
        $padroes = config('sigarb_ajustes_padrao', []);
        foreach ($padroes as $k => $v) {
            if (! isset($linhas[$k])) {
                config(["sigarb.$k" => $v]); // voltou ao padrão
                unset($padroes[$k]);
            }
        }
        foreach (array_intersect_key($linhas, self::catalogo()) as $k => $v) {
            $padroes[$k] ??= self::padraoDoArquivo($k);
            config(["sigarb.$k" => json_decode($v, true)]);
        }
        config(['sigarb_ajustes_padrao' => $padroes]);
    }

    public static function padrao(string $k): mixed
    {
        return config('sigarb_ajustes_padrao', [])[$k] ?? self::padraoDoArquivo($k);
    }

    private static function padraoDoArquivo(string $k): mixed
    {
        return config("sigarb.$k") ?? false; // operação sem a chave = ligada
    }

    /** Valida e normaliza o valor enviado; lança VALIDACAO com mensagem clara. */
    public static function normalizar(string $k, mixed $v): mixed
    {
        $d = self::catalogo()[$k];
        $erro = fn (string $m) => throw new SigarbException('VALOR_INVALIDO', $m, 422);

        return match ($d['tipo']) {
            'bool', 'operacao' => is_bool($v) ? $v : $erro('Informe verdadeiro ou falso.'),
            'int' => is_int($v) && $v >= $d['min'] && $v <= $d['max'] ? $v : $erro("Informe um número inteiro entre {$d['min']} e {$d['max']}."),
            'papeis' => is_array($v) && ! array_diff($v, self::PAPEIS_AUTOAPROVACAO)
                ? array_values(array_intersect(self::PAPEIS_AUTOAPROVACAO, $v)) : $erro('Papel fora da lista permitida.'),
        };
    }
}
