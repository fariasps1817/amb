<?php

namespace Tests\Feature;

use App\Enums\MotivoDeAcesso;
use App\Models\TentativaDeAcesso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Protecao da entrada no sistema.
 *
 * O sistema fica exposto na internet com senhas simples, entao a barreira
 * contra tentativa exaustiva e o limite de tentativas — em duas camadas — e o
 * registro de tudo o que acontece.
 */
class ControleDeAcessoTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login:conta:maria|127.0.0.1');
        RateLimiter::clear('login:origem:127.0.0.1');

        $this->usuario = User::factory()->create([
            'usuario' => 'maria',
            'ativo' => true,
        ]);
    }

    private function tentar(string $usuario, string $senha = 'errada', string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/entrar', ['usuario' => $usuario, 'password' => $senha]);
    }

    // -----------------------------------------------------------------
    // Registro das tentativas
    // -----------------------------------------------------------------

    #[Test]
    public function registra_a_entrada_bem_sucedida(): void
    {
        $this->tentar('maria', '1234')->assertRedirect(route('painel'));

        $tentativa = TentativaDeAcesso::query()->sole();

        $this->assertSame('maria', $tentativa->usuario);
        $this->assertTrue($tentativa->sucesso);
        $this->assertSame(MotivoDeAcesso::Sucesso, $tentativa->motivo);
        $this->assertSame('127.0.0.1', $tentativa->ip);
    }

    #[Test]
    public function distingue_senha_incorreta_de_usuario_inexistente_no_registro(): void
    {
        $this->tentar('maria', 'errada');
        $this->tentar('joao-que-nao-existe', 'qualquer');

        $motivos = TentativaDeAcesso::query()->orderBy('id')->pluck('motivo')->all();

        $this->assertSame(
            [MotivoDeAcesso::SenhaIncorreta, MotivoDeAcesso::UsuarioInexistente],
            $motivos,
        );
    }

    #[Test]
    public function a_tela_de_login_nao_revela_se_o_usuario_existe(): void
    {
        // A distincao vale no registro interno, nunca para quem esta tentando:
        // do contrario o sistema confirmaria quais nomes de usuario existem.
        $comUsuarioReal = $this->tentar('maria', 'errada');
        $comUsuarioFalso = $this->tentar('joao-que-nao-existe', 'errada');

        $comUsuarioReal->assertSessionHasErrors(['usuario' => trans('auth.failed')]);
        $comUsuarioFalso->assertSessionHasErrors(['usuario' => trans('auth.failed')]);
    }

    #[Test]
    public function nunca_grava_a_senha_digitada(): void
    {
        $this->tentar('maria', 'senha-secreta-digitada');

        $registro = TentativaDeAcesso::query()->sole()->toArray();

        $this->assertStringNotContainsString('senha-secreta-digitada', json_encode($registro));
    }

    #[Test]
    public function registra_a_recusa_de_usuario_desativado(): void
    {
        $this->usuario->forceFill(['ativo' => false])->save();

        $this->tentar('maria', '1234')->assertSessionHasErrors('usuario');

        $this->assertSame(MotivoDeAcesso::UsuarioInativo, TentativaDeAcesso::query()->sole()->motivo);
        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // Camada 1: bloqueio da conta
    // -----------------------------------------------------------------

    #[Test]
    public function bloqueia_a_conta_apos_cinco_erros_do_mesmo_computador(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar('maria', 'errada')->assertSessionHasErrors(['usuario' => trans('auth.failed')]);
        }

        $bloqueada = $this->tentar('maria', 'errada');

        $bloqueada->assertSessionHasErrorsIn('default', ['usuario']);
        $this->assertStringContainsString(
            'bloqueado',
            session('errors')->first('usuario'),
        );
    }

    #[Test]
    public function a_senha_correta_nao_entra_com_a_conta_bloqueada(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->tentar('maria', 'errada');
        }

        $this->tentar('maria', '1234');

        $this->assertGuest();
        $this->assertSame(
            MotivoDeAcesso::ContaBloqueada,
            TentativaDeAcesso::query()->latest('id')->first()->motivo,
        );
    }

    #[Test]
    public function a_entrada_bem_sucedida_zera_o_contador(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->tentar('maria', 'errada');
        }

        $this->tentar('maria', '1234')->assertRedirect(route('painel'));

        Auth::logout();

        // Se o contador nao tivesse zerado, o quinto erro ja bloquearia.
        $this->tentar('maria', 'errada')
            ->assertSessionHasErrors(['usuario' => trans('auth.failed')]);
    }

    // -----------------------------------------------------------------
    // Camada 2: bloqueio da origem
    // -----------------------------------------------------------------

    #[Test]
    public function bloqueia_a_origem_que_varre_varios_usuarios_diferentes(): void
    {
        // Este e o caso que a camada de conta sozinha nao pega: cada nome
        // tentado tem o proprio contador, e nenhum chega a cinco erros.
        for ($i = 1; $i <= 20; $i++) {
            $this->tentar("usuario-inventado-{$i}", 'qualquer')
                ->assertSessionHasErrors(['usuario' => trans('auth.failed')]);
        }

        $this->tentar('outro-nome-qualquer', 'seja-o-que-for');

        $this->assertSame(
            MotivoDeAcesso::OrigemBloqueada,
            TentativaDeAcesso::query()->latest('id')->first()->motivo,
        );
    }

    #[Test]
    public function o_bloqueio_de_origem_nao_atinge_outros_computadores(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->tentar("usuario-inventado-{$i}", 'qualquer', '203.0.113.10');
        }

        // Quem esta em outro endereco continua entrando normalmente.
        $this->tentar('maria', '1234', '198.51.100.20')->assertRedirect(route('painel'));
        $this->assertAuthenticated();
    }

    #[Test]
    public function o_bloqueio_da_conta_nao_atinge_os_demais_usuarios(): void
    {
        User::factory()->create(['usuario' => 'joao', 'ativo' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->tentar('maria', 'errada');
        }

        $this->tentar('joao', '1234')->assertRedirect(route('painel'));
        $this->assertAuthenticated();
    }

    // -----------------------------------------------------------------
    // Tela de monitoramento
    // -----------------------------------------------------------------

    #[Test]
    public function a_tela_de_acessos_e_restrita_ao_administrador(): void
    {
        $this->actingAs(User::factory()->create(['perfil' => 'operador']))
            ->get(route('acessos.index'))
            ->assertForbidden();
    }

    #[Test]
    public function o_administrador_ve_as_tentativas_registradas(): void
    {
        $this->tentar('maria', 'errada');
        $this->tentar('invasor', 'chute');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('acessos.index'))
            ->assertOk()
            ->assertSee('maria')
            ->assertSee('invasor')
            ->assertSee('Senha incorreta')
            ->assertSee('Usuário não existe');
    }

    #[Test]
    public function o_filtro_de_falhas_esconde_as_entradas_bem_sucedidas(): void
    {
        $this->tentar('maria', '1234');
        Auth::logout();
        $this->tentar('invasor', 'chute');

        // "Entrou" continua aparecendo na legenda do rodape, que explica todos
        // os resultados possiveis; o que nao pode aparecer e a linha da maria.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('acessos.index', ['filtro' => 'falhas']))
            ->assertOk()
            ->assertSee('invasor')
            ->assertDontSee('maria');
    }

    // -----------------------------------------------------------------
    // Limpeza do historico
    // -----------------------------------------------------------------

    #[Test]
    public function descarta_o_historico_com_mais_de_seis_meses(): void
    {
        // Sem descarte, uma tentativa exaustiva encheria a tabela: sao
        // milhares de linhas em poucos minutos, num servidor de 1 GB.
        $antiga = TentativaDeAcesso::query()->create([
            'usuario' => 'antiga', 'ip' => '203.0.113.1',
            'sucesso' => false, 'motivo' => MotivoDeAcesso::SenhaIncorreta,
        ]);
        $antiga->forceFill(['created_at' => now()->subMonths(7)])->save();

        $recente = TentativaDeAcesso::query()->create([
            'usuario' => 'recente', 'ip' => '203.0.113.2',
            'sucesso' => false, 'motivo' => MotivoDeAcesso::SenhaIncorreta,
        ]);

        $this->artisan('model:prune', ['--model' => [TentativaDeAcesso::class]])->assertSuccessful();

        $this->assertDatabaseMissing('tentativas_de_acesso', ['id' => $antiga->id]);
        $this->assertDatabaseHas('tentativas_de_acesso', ['id' => $recente->id]);
    }

    #[Test]
    public function destaca_a_origem_que_insiste(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->tentar("nome-{$i}", 'chute', '203.0.113.55');
        }

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('acessos.index'))
            ->assertOk()
            ->assertSee('Origens que mais erraram')
            ->assertSee('203.0.113.55');
    }
}
