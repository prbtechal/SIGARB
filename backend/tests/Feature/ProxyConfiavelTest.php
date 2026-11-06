<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** RNF-01/RF-001 — cabeçalhos X-Forwarded-* só valem vindos de proxies explicitamente configurados. */
class ProxyConfiavelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        Route::middleware('web')->get('/_teste/ip', fn () => response()->json(['ip' => request()->ip(), 'https' => request()->isSecure()]));
    }

    private function tentar(string $xff, string $host)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-For' => $xff, 'X-Forwarded-Proto' => 'https'])
            ->postJson("http://$host/api/v1/auth/login", ['login' => $this->c['gestorAlfa']->login, 'password' => 'errada-errada']);
    }

    public function test_host_forge_falsificado_nao_libera_x_forwarded_for_para_burlar_limite_por_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->tentar("198.51.100.$i", 'ataque.on-forge.com')->assertStatus(401);
        }
        // Mesma origem real (REMOTE_ADDR) → limite conta+IP atingido, apesar de XFF diferente a cada vez.
        $this->tentar('198.51.100.99', 'ataque.on-forge.com')->assertStatus(429);
    }

    public function test_sem_proxy_configurado_ip_e_esquema_vem_da_conexao(): void
    {
        $r = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'])->getJson('http://x.on-vapor.com/_teste/ip');
        $r->assertJson(['ip' => '203.0.113.10', 'https' => false]);
    }

    public function test_proxy_explicito_e_o_unico_que_informa_ip_e_esquema(): void
    {
        config(['trustedproxy.proxies' => ['10.20.0.5']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.5'])->withHeaders(['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_teste/ip')->assertJson(['ip' => '198.51.100.7', 'https' => true]);
        // outro IP enviando os mesmos cabeçalhos é ignorado
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.6'])->withHeaders(['X-Forwarded-For' => '198.51.100.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('http://localhost/_teste/ip')->assertJson(['ip' => '10.20.0.6', 'https' => false]);
    }

    public function test_curinga_e_recusado_na_configuracao(): void
    {
        putenv('SIGARB_TRUSTED_PROXIES=*, **,10.1.1.1');
        $_ENV['SIGARB_TRUSTED_PROXIES'] = '*, **,10.1.1.1';
        $cfg = require config_path('trustedproxy.php');
        putenv('SIGARB_TRUSTED_PROXIES');
        unset($_ENV['SIGARB_TRUSTED_PROXIES']);
        $this->assertSame(['10.1.1.1'], $cfg['proxies']);
    }
}
