<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * ADR-015 — passa o histórico gravado em UTC para a hora local de Maceió (UTC-3 fixo, sem horário de verão).
 *
 * - Converte TODAS as colunas DATETIME/TIMESTAMP do schema, tabela por tabela, cada uma numa transação que também grava
 *   a marca persistente em `conversao_fuso` (rodar de novo não subtrai 3 h outra vez).
 * - Tabelas imutáveis: os gatilhos BEFORE UPDATE são guardados na marca ANTES de serem removidos e recriados idênticos
 *   logo depois (também se a conversão falhar ou for retomada). A remoção é temporária, dentro da migração, e fica
 *   registrada na própria cadeia de auditoria.
 * - `eventos_auditoria`: o hash de cada evento cobre `ocorrido_em` como foi gravado (UTC). A conversão roda sob o lock
 *   da cadeia, guarda o último id convertido (`corte_evento_id`) e grava o evento encadeado `sistema.fuso.convertido`
 *   com esse corte; o verificador reconstrói o valor original (+3 h) só para os eventos até o corte.
 * - DATETIME guarda o texto: desloca -3 h. TIMESTAMP é guardado em UTC e convertido pelo fuso da sessão: o deslocamento
 *   é o do fuso da sessão antiga (`@@global.time_zone`, com que o app antigo gravava), aplicado com a sessão em +00:00.
 */
final class ConversaoFusoService
{
    public const ACAO = 'sistema.fuso.convertido';

    public const TABELA_MARCA = 'conversao_fuso';

    public const AUDITORIA = 'eventos_auditoria';

    public const FUSO_ANTERIOR = 'UTC';

    /** America/Maceio = UTC-3 fixo desde 2019 (sem horário de verão). */
    public const DESLOCAMENTO_SEGUNDOS = -10800;

    /** Marca global: conversão concluída ou banco criado já no fuso novo. */
    public const GLOBAL = '*';

    /** Migração que cria a auditoria: se rodou neste mesmo processo, o banco é novo (nada gravado em UTC). */
    public const MIGRACAO_INICIAL = '2026_11_05_000400_create_auditoria_table';

    private const IGNORAR = ['migrations', self::TABELA_MARCA];

    /** Preenchidos pelos ouvintes de eventos de migração (AppServiceProvider). */
    public static bool $migracaoObservada = false;

    /** @var list<string> */
    public static array $migracoesNesteProcesso = [];

    public function __construct(private AuditoriaService $auditoria) {}

    /** Banco novo = a migração inicial rodou neste mesmo `migrate`. Sem observação dos eventos, falha fechada. */
    public static function bancoNovo(): bool
    {
        if (! self::$migracaoObservada) {
            throw new \RuntimeException('SIGARB: conversão de fuso precisa rodar via "php artisan migrate" (eventos de migração não observados).');
        }

        return in_array(self::MIGRACAO_INICIAL, self::$migracoesNesteProcesso, true);
    }

    public function concluida(): bool
    {
        return in_array(DB::table(self::TABELA_MARCA)->where('tabela', self::GLOBAL)->value('estado'), ['CONCLUIDA', 'BANCO_NOVO'], true);
    }

    /**
     * @param  int|null  $deslocamentoSessaoAntiga  segundos do fuso da sessão MySQL do app antigo (padrão: @@global.time_zone)
     * @return array{estado: string, tabelas?: array<string, int>, corte_evento_id?: int}
     */
    public function executar(bool $bancoNovo, ?int $deslocamentoSessaoAntiga = null): array
    {
        if (DB::getDriverName() !== 'mysql' || DB::pretending()) {
            return ['estado' => 'IGNORADA'];
        }
        if ($this->concluida()) {
            $this->garantirGatilhos();

            return ['estado' => 'JA_CONCLUIDA'];
        }
        if ($bancoNovo) {
            $this->marcar(self::GLOBAL, 'BANCO_NOVO', 0, null, ['fuso' => config('app.timezone')]);

            return ['estado' => 'BANCO_NOVO'];
        }

        $antigo = $deslocamentoSessaoAntiga ?? $this->deslocamentoSessaoGlobal();
        $colunas = $this->colunasDeData();
        $tabelas = [];
        foreach ($colunas as $tabela => $cols) {
            if ($tabela !== self::AUDITORIA) {
                $tabelas[$tabela] = $this->converterTabela($tabela, $cols, $antigo);
            }
        }
        $corte = 0;
        if (isset($colunas[self::AUDITORIA])) {
            $tabelas[self::AUDITORIA] = $this->converterTabela(self::AUDITORIA, $colunas[self::AUDITORIA], $antigo,
                function (int $corteEvento) use (&$tabelas, &$corte, $antigo) {
                    $corte = $corteEvento;
                    $this->auditoria->registrar(self::ACAO, 'SUCESSO', 'sistema', null, null, [
                        'fuso_anterior' => self::FUSO_ANTERIOR, 'fuso_novo' => config('app.timezone'),
                        'deslocamento_segundos' => self::DESLOCAMENTO_SEGUNDOS, 'deslocamento_timestamp_segundos' => $antigo,
                        'corte_evento_id' => $corteEvento, 'tabelas' => $tabelas,
                        'gatilhos_recriados' => $this->nomesGatilhosGuardados(),
                    ]);
                });
        }
        $this->garantirGatilhos();
        $this->marcar(self::GLOBAL, 'CONCLUIDA', array_sum($tabelas), null, ['corte_evento_id' => $corte, 'deslocamento_timestamp_segundos' => $antigo]);

        return ['estado' => 'CONCLUIDA', 'tabelas' => $tabelas, 'corte_evento_id' => $corte];
    }

    /**
     * Converte uma tabela (idempotente pela marca). Retorna as linhas convertidas.
     *
     * @param  array<string, string>  $cols  coluna => datetime|timestamp
     */
    private function converterTabela(string $tabela, array $cols, int $antigo, ?callable $auditoria = null): int
    {
        $marca = DB::table(self::TABELA_MARCA)->where('tabela', $tabela)->first();
        if ($marca?->estado === 'CONVERTIDA') {
            return (int) $marca->linhas;
        }
        // Definições dos gatilhos guardadas ANTES de removê-los: uma retomada após queda usa a cópia guardada.
        $gatilhos = $marca ? $this->gatilhosGuardados($marca) : $this->gatilhosDeUpdate($tabela);
        if (! $marca) {
            $this->marcar($tabela, 'EM_ANDAMENTO', 0, $gatilhos);
        }

        try {
            foreach ($gatilhos as $g) {
                DB::unprepared('DROP TRIGGER IF EXISTS `'.$g['nome'].'`');
            }
            $linhas = 0;
            DB::transaction(function () use ($tabela, $cols, $antigo, $auditoria, &$linhas) {
                $corte = null;
                if ($auditoria) {
                    // Mesmo lock da escrita de auditoria: nenhum evento entra entre o corte e o evento de conversão.
                    DB::table('auditoria_cadeia')->where('id', 1)->lockForUpdate()->value('ultimo_hash');
                    $corte = (int) DB::table($tabela)->max('id');
                }
                $sessao = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
                DB::statement("SET time_zone = '+00:00'"); // TIMESTAMP lido/escrito em UTC; DATETIME não é afetado
                try {
                    $sets = [];
                    $binds = [];
                    foreach ($cols as $c => $tipo) {
                        $sets[] = "`$c` = `$c` + INTERVAL ? SECOND";
                        $binds[] = $tipo === 'timestamp' ? $antigo : self::DESLOCAMENTO_SEGUNDOS;
                    }
                    $sql = "UPDATE `$tabela` SET ".implode(', ', $sets);
                    if ($corte !== null) {
                        $sql .= ' WHERE id <= ?';
                        $binds[] = $corte;
                    }
                    $linhas = DB::update($sql, $binds);
                } finally {
                    DB::statement('SET time_zone = ?', [$sessao]);
                }
                $this->marcar($tabela, 'CONVERTIDA', $linhas, null);
                if ($auditoria) {
                    $auditoria($corte);
                }
            });

            return $linhas;
        } finally {
            $this->recriarGatilhos($tabela, $gatilhos);
        }
    }

    /** @return array<string, array<string, string>> tabela => [coluna => tipo] */
    private function colunasDeData(): array
    {
        $linhas = DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS tipo FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('datetime', 'timestamp') AND EXTRA NOT LIKE '%VIRTUAL GENERATED%' AND EXTRA NOT LIKE '%STORED GENERATED%' -- DEFAULT_GENERATED (CURRENT_TIMESTAMP) entra
            ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $out = [];
        foreach ($linhas as $l) {
            if (! in_array($l->t, self::IGNORAR, true)) {
                $out[$l->t][$l->c] = strtolower($l->tipo);
            }
        }

        return $out;
    }

    /** @return list<array{nome: string, momento: string, corpo: string}> */
    private function gatilhosDeUpdate(string $tabela): array
    {
        return array_map(fn ($g) => ['nome' => $g->nome, 'momento' => $g->momento, 'corpo' => $g->corpo], DB::select(
            "SELECT TRIGGER_NAME AS nome, ACTION_TIMING AS momento, ACTION_STATEMENT AS corpo FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? AND EVENT_MANIPULATION = 'UPDATE' ORDER BY ACTION_ORDER", [$tabela]));
    }

    private function recriarGatilhos(string $tabela, array $gatilhos): void
    {
        foreach ($gatilhos as $g) {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.$g['nome'].'`');
            DB::unprepared('CREATE TRIGGER `'.$g['nome'].'` '.$g['momento'].' UPDATE ON `'.$tabela.'` FOR EACH ROW '.$g['corpo']);
        }
    }

    /** Recria qualquer gatilho guardado que esteja ausente (queda entre o commit e a recriação) e confere todos. */
    private function garantirGatilhos(): void
    {
        foreach (DB::table(self::TABELA_MARCA)->whereNotNull('gatilhos')->where('tabela', '<>', self::GLOBAL)->get() as $m) {
            $faltando = array_values(array_filter($this->gatilhosGuardados($m),
                fn ($g) => ! DB::selectOne('SELECT 1 AS x FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$g['nome']])));
            $this->recriarGatilhos($m->tabela, $faltando);
        }
    }

    /** @return list<string> */
    private function nomesGatilhosGuardados(): array
    {
        return DB::table(self::TABELA_MARCA)->whereNotNull('gatilhos')->pluck('gatilhos')
            ->flatMap(fn ($j) => array_column(json_decode((string) $j, true) ?: [], 'nome'))->values()->all();
    }

    /** Deslocamento (s) do fuso padrão das sessões do servidor, com que o app antigo (sem fuso na conexão) gravava. */
    private function deslocamentoSessaoGlobal(): int
    {
        $sessao = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
        try {
            DB::statement('SET time_zone = @@global.time_zone');

            return (int) DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS d')->d;
        } finally {
            DB::statement('SET time_zone = ?', [$sessao]);
        }
    }

    /**
     * Definições guardadas viram DDL executado pelo usuário de migração: só valem com HMAC (APP_KEY) íntegro e nomes
     * válidos. Quem só tem a credencial de runtime do banco (INSERT/UPDATE) não consegue trocar o corpo de um gatilho.
     *
     * @return list<array{nome: string, momento: string, corpo: string}>
     */
    private function gatilhosGuardados(object $marca): array
    {
        $gatilhos = json_decode((string) $marca->gatilhos, true, 4, JSON_THROW_ON_ERROR);
        if (! is_array($gatilhos) || ! hash_equals(self::mac($marca->tabela, $gatilhos), (string) ($marca->gatilhos_mac ?? ''))) {
            throw new \RuntimeException("SIGARB: definição guardada dos gatilhos de {$marca->tabela} adulterada; restaure o backup.");
        }
        foreach ($gatilhos as $g) {
            if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $g['nome'] ?? '') || ! in_array($g['momento'] ?? '', ['BEFORE', 'AFTER'], true) || ! is_string($g['corpo'] ?? null)) {
                throw new \RuntimeException("SIGARB: definição guardada dos gatilhos de {$marca->tabela} inválida.");
            }
        }

        return $gatilhos;
    }

    /** HMAC sobre forma canônica (o MySQL reordena chaves de colunas JSON). */
    private static function mac(string $tabela, array $gatilhos): string
    {
        $canonico = array_map(function ($g) {
            ksort($g, SORT_STRING);

            return $g;
        }, array_values($gatilhos));

        return hash_hmac('sha256', $tabela.'|'.json_encode($canonico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }

    private function marcar(string $tabela, string $estado, int $linhas, ?array $gatilhos, ?array $detalhes = null): void
    {
        $dados = ['estado' => $estado, 'linhas' => $linhas, 'atualizado_em' => now()->format('Y-m-d H:i:s.u')];
        if ($gatilhos !== null) {
            $dados['gatilhos'] = json_encode($gatilhos, JSON_UNESCAPED_UNICODE);
            $dados['gatilhos_mac'] = self::mac($tabela, $gatilhos);
        }
        if ($detalhes !== null) {
            $dados['detalhes'] = json_encode($detalhes, JSON_UNESCAPED_UNICODE);
        }
        DB::table(self::TABELA_MARCA)->updateOrInsert(['tabela' => $tabela], $dados);
    }
}
