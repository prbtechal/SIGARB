<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RF-011/012/019/021, RN-006/012/013/014/015 — operações com regra PROVISÓRIA (desligadas por padrão).
// vinculo_id liga a conclusão à abertura (envio→recebimento, reserva→liberação, manutenção→retorno); UNIQUE = conclusão única.
return new class extends Migration
{
    private array $tipos = ['ENTRADA', 'ESTORNO', 'SAIDA', 'ENTREGA', 'DEVOLUCAO', 'TRANSFERENCIA', 'RESERVA', 'BAIXA', 'AJUSTE'];

    private array $situacoes = ['DISPONIVEL', 'RESERVADO', 'ACAUTELADO', 'MANUTENCAO', 'BAIXADO', 'ESTORNADO'];

    public function up(): void
    {
        Schema::table('movimentacoes', function (Blueprint $t) {
            $t->foreignId('vinculo_id')->nullable()->unique()->after('estorno_de_id')->constrained('movimentacoes')->restrictOnDelete();
            $t->boolean('provisoria')->default(false)->after('vinculo_id');
        });
        if (DB::getDriverName() === 'mysql') {
            $tipos = array_merge($this->tipos, ['RECEBIMENTO', 'LIBERACAO_RESERVA', 'MANUTENCAO', 'RETORNO_MANUTENCAO']);
            $sit = array_merge($this->situacoes, ['EM_TRANSITO', 'FORA_DO_ESTOQUE']);
            DB::statement("ALTER TABLE movimentacoes MODIFY tipo ENUM('".implode("','", $tipos)."') NOT NULL");
            DB::statement("ALTER TABLE itens_individuais MODIFY situacao ENUM('".implode("','", $sit)."') NOT NULL DEFAULT 'DISPONIVEL'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE itens_individuais MODIFY situacao ENUM('".implode("','", $this->situacoes)."') NOT NULL DEFAULT 'DISPONIVEL'");
            DB::statement("ALTER TABLE movimentacoes MODIFY tipo ENUM('".implode("','", $this->tipos)."') NOT NULL");
        }
        Schema::table('movimentacoes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vinculo_id');
            $t->dropColumn('provisoria');
        });
    }
};
