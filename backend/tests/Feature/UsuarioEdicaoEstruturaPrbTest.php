<?php

namespace Tests\Feature;

use App\Models\Unidade;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioEdicaoEstruturaPrbTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_edita_todos_os_dados_da_conta(): void
    {
        $this->cenario();
        $alvo = Usuario::factory()->create(['login' => 'antigo.login']);
        $this->como($this->c['admin'])->putJson("/api/v1/users/{$alvo->id}", ['nome' => 'Nome Novo', 'login' => 'novo.login', 'email' => 'novo@exemplo.test'])
            ->assertOk()->assertJsonPath('login', 'novo.login')->assertJsonPath('nome', 'Nome Novo');
        $outro = Usuario::factory()->create(['login' => 'ja.existe']);
        $this->putJson("/api/v1/users/{$alvo->id}", ['login' => 'ja.existe'])->assertStatus(422);
        $this->putJson("/api/v1/users/{$alvo->id}", ['login' => 'Inválido!'])->assertStatus(422);
        // Sem usuarios.gerir não edita.
        $this->como($this->c['gestorAlfa'])->putJson("/api/v1/users/{$outro->id}", ['login' => 'x.y.z'])->assertForbidden();
    }

    public function test_estrutura_prb_criada_pela_migracao(): void
    {
        $orla = Unidade::where('sigla', 'MACEIO-POCO-ORLA')->firstOrFail();
        $poco = Unidade::where('sigla', 'MACEIO-POCO')->firstOrFail();
        $maceio = Unidade::where('sigla', 'MACEIO')->firstOrFail();
        $this->assertSame('PRB', $maceio->organizacao->sigla);
        $this->assertTrue($maceio->contem($orla) && $poco->contem($orla));
        $this->assertSame('Orla', $orla->nome);
        foreach (['ARAPIRACA', 'PARIPUEIRA', 'RIO-LARGO', 'MARECHAL-DEODORO-FRANCES', 'MACEIO-BENEDITO'] as $s) {
            $this->assertTrue(Unidade::where('sigla', $s)->exists(), $s);
        }
    }
}
