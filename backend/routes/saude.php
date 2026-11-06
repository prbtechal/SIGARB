<?php

use App\Http\Controllers\Api\SaudeController;
use App\Http\Middleware\LimiteSaude;
use Illuminate\Support\Facades\Route;

// RNF-09 — sondas de saúde, prefixo /api/v1, FORA do grupo "web" (CP-8): sem sessão, cookies ou CSRF,
// para que nenhuma dependência do banco (SESSION_DRIVER/CACHE_STORE=database) derrube o liveness.
Route::get('health/live', [SaudeController::class, 'vivo'])->middleware(LimiteSaude::class.':60');
Route::get('health/ready', [SaudeController::class, 'pronto'])->middleware(LimiteSaude::class.':30');
