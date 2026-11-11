<?php

use App\Models\AtribuicaoPapel;
use App\Models\Documento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Papel;
use App\Models\TipoDocumento;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use App\Services\CargaInventarioService;
use App\Services\DispositivoService;
use App\Services\DocumentoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('sigarb:auditoria:verificar', function (AuditoriaService $s) {
    $invalido = $s->verificar();
    if ($invalido === null) {
        $this->info('Cadeia de auditoria íntegra.');

        return 0;
    }
    $this->error("Cadeia de auditoria QUEBRADA a partir do evento #$invalido.");

    return 1;
})->purpose('Verifica o encadeamento de hash da trilha de auditoria (ADR-007)');

Artisan::command('sigarb:documentos:verificar', function () {
    // Confere cada documento registrado com o arquivo no disco privado (existência + SHA-256). Usado na restauração.
    $disco = Storage::disk('documentos');
    $total = 0;
    $falhas = 0;
    foreach (Documento::query()->orderBy('id')->lazy(200) as $d) {
        $total++;
        if (! $disco->exists($d->caminho()) || ! hash_equals($d->sha256, (string) hash_file('sha256', $disco->path($d->caminho())))) {
            $falhas++;
            $this->error("Documento {$d->codigo_publico}: arquivo ausente ou checksum divergente.");
        }
    }
    $this->line("documentos={$total} falhas={$falhas}");

    return $falhas === 0 ? 0 : 1;
})->purpose('Verifica existência e SHA-256 dos arquivos de documentos');

Artisan::command('sigarb:carga:preparar {--usuarios=120} {--documentos=6}', function () {
    // Dados SINTÉTICOS para teste de carga local. Proibido em produção.
    if (app()->isProduction()) {
        $this->error('Proibido em produção.');

        return 1;
    }
    $senha = env('SIGARB_DEMO_PASSWORD');
    if (! $senha) {
        $this->error('SIGARB_DEMO_PASSWORD ausente.');

        return 1;
    }
    $papel = Papel::where('codigo', 'GESTOR')->value('id');
    $un = Unidade::where('sigla', 'UN-ALFA')->value('id');
    for ($i = 1; $i <= (int) $this->option('usuarios'); $i++) {
        $u = Usuario::firstOrCreate(['login' => sprintf('carga.%03d', $i)], ['nome' => "Carga sintética $i", 'password' => $senha]);
        AtribuicaoPapel::firstOrCreate(['usuario_id' => $u->id, 'papel_id' => $papel, 'unidade_id' => $un]);
    }
    $g = Usuario::where('login', 'gestor.alfa')->firstOrFail();
    auth()->setUser($g);
    while (Documento::count() < (int) $this->option('documentos')) {
        $f = tempnam(sys_get_temp_dir(), 'carga');
        file_put_contents($f, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        app(DocumentoService::class)->armazenar($g, new UploadedFile($f, 'carga.pdf', null, null, true),
            ['tipo_documento_id' => TipoDocumento::where('ativo', true)->value('id'), 'unidade_id' => $un, 'titulo' => 'Documento de carga '.(Documento::count() + 1)]);
    }
    $this->line(LocalEstoque::where('codigo', 'RES-ALFA-01')->value('id').' '
        .Material::where('codigo', 'DEMO-MUN-01')->value('id').' '
        .Documento::pluck('codigo_publico')->implode(','));

    return 0;
})->purpose('Prepara dados sintéticos de carga (não-produção)');

Artisan::command('sigarb:inventario:importar {arquivo} {--simular} {--usuario=}', function (CargaInventarioService $carga) {
    // Carga inicial do inventário de material bélico (.xlsx com abas por território). Ver CargaInventarioService.
    $u = Usuario::find((int) $this->option('usuario'));
    if (! $u) {
        $this->error('Informe --usuario=<id> do Administrador que assina a carga.');

        return 2;
    }
    if (! is_file($this->argument('arquivo'))) {
        $this->error('Arquivo não encontrado.');

        return 2;
    }
    $r = $carga->executar((string) file_get_contents($this->argument('arquivo')), $u, (bool) $this->option('simular'));
    foreach ($r['relatorio'] as $l) {
        $this->line($l);
    }

    return $r['ok'] ? 0 : 1;
})->purpose('Carrega o inventário de material bélico (planilha .xlsx) via EstoqueService; --simular desfaz tudo');

Artisan::command('sigarb:contas:desativar-inativas {--simular : Só lista, sem desativar}', function () {
    // PSI-PRB 9.3: conta sem acesso há mais de N dias é desabilitada (nunca apagada). Sem login algum, conta a partir da criação.
    $dias = (int) config('sigarb.contas.inatividade_dias');
    $limite = now()->subDays($dias);
    $contas = Usuario::where('ativo', true)->whereNotIn('login', config('sigarb.contas.isentas_inatividade'))
        ->whereRaw('COALESCE(ultimo_login_em, created_at) < ?', [$limite])->orderBy('id')->get();
    foreach ($contas as $u) {
        $ultimo = ($u->ultimo_login_em ?? $u->created_at)?->format('d/m/Y');
        $this->line(($this->option('simular') ? '[simulação] ' : '')."#{$u->id} {$u->nome} — último acesso/criação: {$ultimo}");
        if ($this->option('simular')) {
            continue;
        }
        DB::transaction(function () use ($u, $dias, $ultimo) {
            $u->forceFill(['ativo' => false, 'desativado_em' => now()])->saveQuietly();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            app(DispositivoService::class)->revogarTodos($u->id);
            app(AuditoriaService::class)->registrar('usuario.desativado_inatividade', 'SUCESSO', 'usuario', $u->id,
                dados: ['dias_sem_acesso_limite' => $dias, 'ultimo_acesso_ou_criacao' => $ultimo]);
        });
    }
    $this->info(($this->option('simular') ? 'Seriam desativadas' : 'Desativadas').': '.$contas->count()." conta(s) sem acesso há mais de {$dias} dias.");

    return 0;
})->purpose('Desativa contas sem acesso há mais de 60 dias (PSI-PRB 9.3)');

// Produção: requer o cron/timer do agendador (`php artisan schedule:run` a cada minuto) — ver deploy/RUNBOOK.md.
Schedule::command('sigarb:contas:desativar-inativas')->dailyAt('03:15')->withoutOverlapping();
