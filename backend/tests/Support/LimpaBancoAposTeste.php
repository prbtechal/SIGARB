<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Para testes com DatabaseTruncation: dados comitados não podem vazar para os testes com RefreshDatabase. */
trait LimpaBancoAposTeste
{
    protected function limparBanco(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            foreach (DB::select('SHOW TABLES') as $t) {
                $n = array_values((array) $t)[0];
                if (! in_array($n, ['migrations', 'auditoria_cadeia'], true)) {
                    DB::table($n)->truncate();
                }
            }
            DB::table('auditoria_cadeia')->update(['ultimo_hash' => str_repeat('0', 64)]);
        });
    }
}
