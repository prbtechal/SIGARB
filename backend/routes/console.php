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
