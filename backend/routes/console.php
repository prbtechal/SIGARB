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
