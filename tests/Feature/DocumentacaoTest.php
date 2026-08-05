<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acesso a documentacao tecnica pelo navegador.
 *
 * Os guias descrevem o endereco do servidor, os caminhos dos arquivos e como a
 * seguranca esta montada. Deixa-los abertos seria entregar um roteiro de
 * invasao, entao o ponto central destes testes e que ninguem alcance a
 * documentacao sem estar autenticado como administrador.
 */
class DocumentacaoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function visitante_nao_alcanca_a_documentacao(): void
    {
        $this->get(route('documentacao.index'))->assertRedirect(route('login'));
        $this->get(route('documentacao.mostrar', 'hospedagem'))->assertRedirect(route('login'));
    }

    #[Test]
    public function operador_nao_alcanca_a_documentacao(): void
    {
        $operador = User::factory()->create(['perfil' => 'operador']);

        $this->actingAs($operador)->get(route('documentacao.index'))->assertForbidden();
        $this->actingAs($operador)->get(route('documentacao.mostrar', 'hospedagem'))->assertForbidden();
    }

    #[Test]
    public function o_administrador_ve_a_relacao_de_documentos(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('documentacao.index'))
            ->assertOk()
            ->assertSee('Montar o servidor do zero');
    }

    #[Test]
    public function o_administrador_abre_o_guia(): void
    {
        $resposta = $this->actingAs(User::factory()->admin()->create())
            ->get(route('documentacao.mostrar', 'hospedagem'));

        // Se o HTML ainda nao foi gerado neste ambiente, o 404 e o
        // comportamento correto e nao ha o que testar alem disso.
        if (! is_file(base_path('docs/hospedagem-oracle-cloud.html'))) {
            $resposta->assertNotFound();

            return;
        }

        $resposta->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('Montar o servidor do zero', escape: false);
    }

    #[Test]
    public function a_documentacao_nao_e_indexada_por_buscadores(): void
    {
        if (! is_file(base_path('docs/hospedagem-oracle-cloud.html'))) {
            $this->markTestSkipped('HTML do guia não gerado neste ambiente.');
        }

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('documentacao.mostrar', 'hospedagem'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function nome_de_documento_desconhecido_devolve_404(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('documentacao.mostrar', 'inexistente'))
            ->assertNotFound();
    }

    #[Test]
    public function nao_e_possivel_pedir_arquivos_de_fora_da_pasta(): void
    {
        // A lista de documentos e fixa justamente para isto: se o nome viesse
        // da URL, "../.env" entregaria a senha do banco.
        $admin = User::factory()->admin()->create();

        foreach (['../.env', '..%2F.env', '....//.env', '/etc/passwd'] as $tentativa) {
            $this->actingAs($admin)
                ->get('/documentacao/'.$tentativa)
                ->assertNotFound();
        }
    }
}
