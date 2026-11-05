<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenciaSeeder::class);
        if (! app()->isProduction()) {
            $this->call(DemonstracaoSeeder::class);
        }
    }
}
