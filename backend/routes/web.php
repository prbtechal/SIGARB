<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['sistema' => 'SIGARB API', 'ambiente' => 'nao-producao', 'api' => '/api/v1']));
