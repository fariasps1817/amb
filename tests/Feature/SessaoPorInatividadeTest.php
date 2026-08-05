<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Encerramento da sessao por inatividade.
 *
 * Quem encerra de fato e o servidor, pela configuracao session.lifetime. O
 * aviso na tela serve para que isso nao aconteca em silencio, e a rota de
 * renovacao para que quem esta trabalhando nao seja desconectado.
 */
class SessaoPorInatividadeTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create(['ativo' => true]);
    }

    #[Test]
    public function a_sessao_expira_por_tempo_e_nao_apenas_ao_fechar_o_navegador(): void
    {
        // Se expire_on_close estivesse ligado, a sessao duraria enquanto a aba
        // ficasse aberta, por mais ociosa que estivesse.
        $this->assertFalse(config('session.expire_on_close'));
        $this->assertGreaterThan(0, (int) config('session.lifetime'));
    }

    #[Test]
    public function o_aviso_de_inatividade_aparece_nas_telas_internas(): void
    {
        $this->actingAs($this->usuario)
            ->get(route('painel'))
            ->assertOk()
            ->assertSee('avisoDeInatividade', escape: false)
            ->assertSee('Continuar conectado');
    }

    #[Test]
    public function o_aviso_usa_o_mesmo_tempo_configurado_no_servidor(): void
    {
        config(['session.lifetime' => 45]);

        $this->actingAs($this->usuario)
            ->get(route('painel'))
            ->assertOk()
            ->assertSee('minutos: 45', escape: false);
    }

    #[Test]
    public function a_rota_de_renovacao_exige_estar_autenticado(): void
    {
        $this->post(route('sessao.renovar'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_rota_de_renovacao_responde_sem_conteudo(): void
    {
        // Ela existe so para gerar uma requisicao e adiar a expiracao; nao
        // precisa devolver nada, e devolver nada mantem a chamada barata.
        $this->actingAs($this->usuario)
            ->post(route('sessao.renovar'))
            ->assertNoContent();
    }

    #[Test]
    public function sair_por_inatividade_explica_o_motivo_na_tela_de_login(): void
    {
        $this->actingAs($this->usuario)
            ->post(route('logout'), ['inatividade' => 1])
            ->assertRedirect(route('login'))
            ->assertSessionHas('atencao', trans('auth.inatividade'));

        $this->assertGuest();
    }

    #[Test]
    public function sair_pelo_botao_nao_fala_em_inatividade(): void
    {
        $this->actingAs($this->usuario)
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('sucesso', 'Sessão encerrada.')
            ->assertSessionMissing('atencao');
    }

    #[Test]
    public function a_tela_de_login_mostra_o_aviso_de_sessao_encerrada(): void
    {
        $this->actingAs($this->usuario)->post(route('logout'), ['inatividade' => 1]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('encerrada por inatividade');
    }
}
