<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Militar;
use App\Support\FotoSanitizada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Foto do perfil do efetivo: sem metadados, privada, por escopo e permissão. */
class MilitarFotoTest extends TestCase
{
    use RefreshDatabase;

    private function seg(int $marcador, string $dados): string
    {
        return chr(0xFF).chr($marcador).pack('n', strlen($dados) + 2).$dados;
    }

    private function jpegComGps(): string
    {
        return "\xFF\xD8".$this->seg(0xE1, "Exif\0\0GPS-LATITUDE-9.66").$this->seg(0xFE, 'comentario do celular')
            .$this->seg(0xDB, str_repeat("\x01", 65)).$this->seg(0xC0, "\x08\x00\x10\x00\x10\x01\x01\x11\x00")
            .$this->seg(0xDA, "\x01\x01\x00\x00\x3F\x00")."\x12\x34\x56\xFF\xD9";
    }

    private function chunk(string $tipo, string $dados): string
    {
        return pack('N', strlen($dados)).$tipo.$dados.pack('N', crc32($tipo.$dados));
    }

    private function pngComTexto(): string
    {
        return "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            .$this->chunk('tEXt', "Author\0Pessoa real").$this->chunk('IDAT', gzcompress("\0\0\0\0")).$this->chunk('IEND', '');
    }

    public function test_sanitizador_remove_metadados_e_recusa_lixo(): void
    {
        [$j, $ext] = FotoSanitizada::limpar($this->jpegComGps());
        $this->assertSame('jpg', $ext);
        $this->assertStringNotContainsString('GPS', $j);
        $this->assertStringNotContainsString('comentario', $j);
        $this->assertStringEndsWith("\xFF\xD9", $j);
        [$p] = FotoSanitizada::limpar($this->pngComTexto());
        $this->assertStringNotContainsString('Pessoa real', $p);
        $this->assertStringContainsString('IDAT', $p);
        $this->assertNull(FotoSanitizada::limpar('<?php echo 1;'));
        $this->assertNull(FotoSanitizada::limpar("\xFF\xD8\xFF\xE0\x00")); // truncado
        $ruim = $this->pngComTexto();
        $ruim[30] = 'X'; // CRC deixa de bater
        $this->assertNull(FotoSanitizada::limpar($ruim));
    }

    public function test_envio_consulta_escopo_e_remocao(): void
    {
        Storage::fake('documentos');
        $this->cenario();
        $ef = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $up = fn (string $b, string $nome) => UploadedFile::fake()->createWithContent($nome, $b);

        $this->como($this->c['operadorAlfa1'])->post("/api/v1/militares/{$ef->id}/foto", ['foto' => $up($this->jpegComGps(), 'f.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->como($this->c['gestorAlfa'])->post("/api/v1/militares/{$ef->id}/foto", ['foto' => $up('não é imagem', 'f.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'FOTO_INVALIDA');
        $v = $this->post("/api/v1/militares/{$ef->id}/foto", ['foto' => $up($this->jpegComGps(), 'f.jpg')], ['Accept' => 'application/json'])->assertOk()->json('foto_versao');
        $this->assertNotNull($v);
        $foto = $this->get("/api/v1/militares/{$ef->id}/foto")->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringNotContainsString('GPS', $foto->getContent());
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'militar.foto_alterada']);
        $this->assertArrayNotHasKey('foto_caminho', $this->getJson("/api/v1/militares/{$ef->id}")->json());
        $this->como($this->c['gestorBravo'])->get("/api/v1/militares/{$ef->id}/foto")->assertForbidden();
        $this->como($this->c['gestorAlfa'])->deleteJson("/api/v1/militares/{$ef->id}/foto")->assertOk()->assertJsonPath('foto_versao', null);
        $this->assertSame([], Storage::disk('documentos')->allFiles('efetivos'));
        $this->get("/api/v1/militares/{$ef->id}/foto")->assertNotFound();
    }

    public function test_filtros_por_efetivo_e_admin_gere_catalogo(): void
    {
        $this->cenario();
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/movements?militar_id=999')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/acautelamentos?militar_id=999')->assertOk()->assertJsonPath('meta.total', 0);
        $cat = Categoria::firstOrCreate(['codigo' => 'EQP-T'], ['nome' => 'Equipamento teste', 'natureza' => 'EQUIPAMENTO']);
        $this->como($this->c['admin'])->postJson('/api/v1/items', ['codigo' => 'ADM-T1', 'nome' => 'Item do admin', 'modo_controle' => 'LOTE', 'categoria_id' => $cat->id])->assertCreated();
        $this->getJson('/api/v1/stock/balances')->assertForbidden(); // ADMIN continua sem operar estoque
    }
}
