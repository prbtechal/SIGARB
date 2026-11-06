<?php

use App\Services\AuditoriaService;
use Illuminate\Support\Facades\Artisan;

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
    $disco = \Illuminate\Support\Facades\Storage::disk('documentos');
    $total = 0;
    $falhas = 0;
    foreach (\App\Models\Documento::query()->orderBy('id')->lazy(200) as $d) {
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
    $papel = \App\Models\Papel::where('codigo', 'GESTOR')->value('id');
    $un = \App\Models\Unidade::where('sigla', 'UN-ALFA')->value('id');
    for ($i = 1; $i <= (int) $this->option('usuarios'); $i++) {
        $u = \App\Models\Usuario::firstOrCreate(['login' => sprintf('carga.%03d', $i)], ['nome' => "Carga sintética $i", 'password' => $senha]);
        \App\Models\AtribuicaoPapel::firstOrCreate(['usuario_id' => $u->id, 'papel_id' => $papel, 'unidade_id' => $un]);
    }
    $g = \App\Models\Usuario::where('login', 'gestor.alfa')->firstOrFail();
    auth()->setUser($g);
    while (\App\Models\Documento::count() < (int) $this->option('documentos')) {
        $f = tempnam(sys_get_temp_dir(), 'carga');
        file_put_contents($f, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        app(\App\Services\DocumentoService::class)->armazenar($g, new \Illuminate\Http\UploadedFile($f, 'carga.pdf', null, null, true),
            ['tipo_documento_id' => \App\Models\TipoDocumento::where('ativo', true)->value('id'), 'unidade_id' => $un, 'titulo' => 'Documento de carga '.(\App\Models\Documento::count() + 1)]);
    }
    $this->line(\App\Models\LocalEstoque::where('codigo', 'RES-ALFA-01')->value('id').' '
        .\App\Models\Material::where('codigo', 'DEMO-MUN-01')->value('id').' '
        .\App\Models\Documento::pluck('codigo_publico')->implode(','));

    return 0;
})->purpose('Prepara dados sintéticos de carga (não-produção)');
