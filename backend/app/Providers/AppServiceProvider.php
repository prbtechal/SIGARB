<?php

namespace App\Providers;

use App\Services\ConversaoFusoService;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ADR-015: a conversão de fuso distingue banco novo (migração inicial neste mesmo migrate) de banco com histórico UTC.
        Event::listen(MigrationsStarted::class, function (MigrationsStarted $e) {
            if ($e->method === 'up') {
                ConversaoFusoService::$migracaoObservada = true;
                ConversaoFusoService::$migracoesNesteProcesso = [];
            }
        });
        Event::listen(MigrationEnded::class, function (MigrationEnded $e) {
            if ($e->method === 'up' && $e->name !== null) {
                ConversaoFusoService::$migracoesNesteProcesso[] = $e->name;
            }
        });
    }
}
