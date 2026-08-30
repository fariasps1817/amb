<?php

namespace Tests\Feature;

use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\EscalaMensagem;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Whatsapp\Contracts\DriverDeWhatsapp;
use App\Services\Whatsapp\Drivers\DriverCloudApi;
use App\Services\Whatsapp\Drivers\DriverDeLink;
use App\Services\Whatsapp\EnviadorDeMensagens;
use App\Services\Whatsapp\MontadorDeMensagens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Comunicacao da escala aos motoristas pelo WhatsApp.
 */
class MensagensTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operador = User::factory()->admin()->create();
        $this->actingAs($this->operador);

        Configuracao::atual()->update([
            'secretaria' => 'Secretaria Municipal de Saúde',
            'setor' => 'Coordenação de Ambulâncias',
            'telefone_1' => '8533342244',
        ]);
    }

    // -----------------------------------------------------------------
    // Montagem do texto
    // -----------------------------------------------------------------

    /**
     * O requisito central: a mensagem lista os dias em que o motorista esta de
     * plantao no mes.
     */
    #[Test]
    public function monta_a_mensagem_com_os_dias_de_plantao(): void
    {
        $escala = $this->escalaCompleta(descanso: 48); // 24/48: plantão a cada 3 dias
        $primeiroPlantao = $escala->plantoes()->orderBy('data')->first();
        $motorista = $primeiroPlantao->motorista;

        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $mensagem = EscalaMensagem::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $motorista->id)
            ->firstOrFail();

        $texto = $mensagem->texto;

        // Cabeçalho com o mês de referência e a identificação do setor.
        $this->assertStringContainsString('AGOSTO/2026', $texto);
        $this->assertStringContainsString('Coordenação de Ambulâncias', $texto);

        // Saudação pelo tratamento inteiro do cadastro, e não só a primeira
        // palavra dele: há vários Franciscos e Carlos na equipe, e o nome curto
        // é justamente onde o setor registra por qual nome cada um é conhecido.
        // Guarda contra o teste virar vazio: com um tratamento de uma palavra
        // so, a asserção passaria mesmo se voltassemos a cortar o nome.
        $this->assertStringContainsString(' ', $motorista->nome_curto);
        $this->assertStringContainsString('Olá, '.$motorista->nome_curto.'!', $texto);

        // Unidade, ambulância e regime.
        $this->assertStringContainsString('UPA CENTRO', $texto);
        $this->assertStringContainsString('THQ4H34', $texto);
        $this->assertStringContainsString('24/48', $texto);

        // Todas as datas de plantão do motorista aparecem na lista.
        $datas = $escala->plantoes()
            ->where('motorista_id', $motorista->id)
            ->orderBy('data')
            ->pluck('data');

        foreach ($datas as $data) {
            $this->assertStringContainsString($data->format('d/m'), $texto);
        }

        // A quantidade é informada e o horário do plantão também.
        $this->assertStringContainsString($datas->count().' plantões', $texto);
        $this->assertStringContainsString('07:00', $texto);
        $this->assertStringContainsString('8533342244', preg_replace('/\D/', '', $texto));
    }

    /** Cada motorista recebe apenas os seus dias. */
    #[Test]
    public function cada_motorista_recebe_somente_os_seus_dias(): void
    {
        $escala = $this->escalaCompleta();

        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $mensagens = EscalaMensagem::query()->where('escala_id', $escala->id)->with('motorista')->get();

        $this->assertCount(4, $mensagens);

        foreach ($mensagens as $mensagem) {
            $meus = $escala->plantoes()
                ->where('motorista_id', $mensagem->motorista_id)
                ->pluck('data')
                ->map(fn ($d) => $d->format('d/m'));

            $deOutros = $escala->plantoes()
                ->where('motorista_id', '!=', $mensagem->motorista_id)
                ->pluck('data')
                ->map(fn ($d) => $d->format('d/m'))
                ->diff($meus);

            foreach ($meus as $data) {
                $this->assertStringContainsString($data, $mensagem->texto);
            }

            foreach ($deOutros as $data) {
                $this->assertStringNotContainsString(
                    "• {$data}",
                    $mensagem->texto,
                    "A mensagem de {$mensagem->motorista->nome_curto} não deve citar o dia {$data} de outro motorista."
                );
            }
        }
    }

    /** Quem nao esta escalado nao recebe mensagem. */
    #[Test]
    public function nao_prepara_mensagem_para_quem_nao_esta_escalado(): void
    {
        $escala = $this->escalaCompleta();
        $reserva = Motorista::factory()->create();

        app(MontadorDeEscala::class)->definirDestino($escala, $reserva->id, TipoDestino::Reserva);
        app(MontadorDeMensagens::class)->prepararParaEscala($escala->fresh());

        $this->assertFalse(
            EscalaMensagem::query()->where('motorista_id', $reserva->id)->exists()
        );
    }

    /** Sem telefone nao ha como comunicar; o sistema aponta quem falta. */
    #[Test]
    public function aponta_escalados_sem_telefone(): void
    {
        $escala = $this->escalaCompleta();
        $motorista = $escala->plantoes()->first()->motorista;
        $motorista->update(['telefone_1' => null]);

        $semTelefone = app(MontadorDeMensagens::class)->semTelefone($escala->fresh());

        $this->assertCount(1, $semTelefone);
        $this->assertSame($motorista->id, $semTelefone->first()->id);
    }

    /** Regerar nao apaga o registro do que ja foi enviado. */
    #[Test]
    public function preserva_mensagens_ja_enviadas(): void
    {
        $escala = $this->escalaCompleta();
        $montador = app(MontadorDeMensagens::class);

        $montador->prepararParaEscala($escala);

        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->first();
        $mensagem->marcarEnviada('link');

        $preparadas = $montador->prepararParaEscala($escala->fresh());

        // As outras três foram atualizadas; a enviada foi mantida.
        $this->assertSame(3, $preparadas);
        $this->assertTrue($mensagem->fresh()->foiEnviada());

        // Com o sinalizador, todas são recriadas.
        $this->assertSame(4, $montador->prepararParaEscala($escala->fresh(), recriarEnviadas: true));
        $this->assertFalse($mensagem->fresh()->foiEnviada());
    }

    // -----------------------------------------------------------------
    // Link wa.me
    // -----------------------------------------------------------------

    /** O link leva o número em E.164 e o texto codificado. */
    #[Test]
    public function monta_o_link_do_whatsapp(): void
    {
        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->first();
        $mensagem->update(['telefone' => '98692 6853']);

        $link = $mensagem->fresh()->link();

        $this->assertStringStartsWith('https://wa.me/5585986926853?text=', $link);
        $this->assertStringContainsString('AGOSTO', rawurldecode($link));
    }

    #[Test]
    public function driver_de_link_nao_envia_sozinho(): void
    {
        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);
        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->first();

        $this->post(route('mensagens.enviar', [$escala, $mensagem]))
            ->assertSessionHas('atencao');

        // Continua pendente até o operador confirmar.
        $this->assertFalse($mensagem->fresh()->foiEnviada());
    }

    #[Test]
    public function operador_confirma_o_envio_manual(): void
    {
        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);
        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->first();

        $this->post(route('mensagens.enviada', [$escala, $mensagem]))
            ->assertSessionHas('sucesso');

        $mensagem->refresh();

        $this->assertTrue($mensagem->foiEnviada());
        $this->assertSame('link', $mensagem->driver);
        $this->assertSame($this->operador->id, $mensagem->enviada_por);
        $this->assertNotNull($mensagem->enviada_em);
    }

    /** Com o driver de link, o envio em lote nao se aplica. */
    #[Test]
    public function envio_em_lote_avisa_que_exige_api(): void
    {
        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $this->post(route('mensagens.enviar-todas', $escala))->assertSessionHas('atencao');
    }

    // -----------------------------------------------------------------
    // Driver de API
    // -----------------------------------------------------------------

    #[Test]
    public function envia_pela_cloud_api(): void
    {
        config([
            'whatsapp.driver' => 'cloud',
            'whatsapp.cloud.token' => 'token-de-teste',
            'whatsapp.cloud.phone_id' => '123456',
        ]);
        $this->app->bind(DriverDeWhatsapp::class, fn () => new DriverCloudApi);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200),
        ]);

        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);
        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->first();

        $this->post(route('mensagens.enviar', [$escala, $mensagem]))->assertSessionHas('sucesso');

        $mensagem->refresh();

        $this->assertTrue($mensagem->foiEnviada());
        $this->assertSame('cloud', $mensagem->driver);
        $this->assertStringContainsString('wamid.ABC', (string) $mensagem->retorno);
    }

    #[Test]
    public function registra_falha_da_api(): void
    {
        config([
            'whatsapp.driver' => 'cloud',
            'whatsapp.cloud.token' => 'token-de-teste',
            'whatsapp.cloud.phone_id' => '123456',
        ]);
        $this->app->bind(DriverDeWhatsapp::class, fn () => new DriverCloudApi);

        Http::fake([
            'graph.facebook.com/*' => Http::response(
                ['error' => ['message' => 'Número não registrado no WhatsApp']],
                400
            ),
        ]);

        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);
        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->first();

        $this->post(route('mensagens.enviar', [$escala, $mensagem]))->assertSessionHas('erro');

        $mensagem->refresh();

        $this->assertTrue($mensagem->comErro());
        $this->assertStringContainsString('não registrado', (string) $mensagem->retorno);
    }

    #[Test]
    public function envia_todas_as_pendentes_pela_api(): void
    {
        config([
            'whatsapp.driver' => 'cloud',
            'whatsapp.cloud.token' => 'token-de-teste',
            'whatsapp.cloud.phone_id' => '123456',
        ]);
        $this->app->bind(DriverDeWhatsapp::class, fn () => new DriverCloudApi);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $this->post(route('mensagens.enviar-todas', $escala))->assertSessionHas('sucesso');

        $this->assertSame(
            4,
            EscalaMensagem::query()->where('escala_id', $escala->id)->enviadas()->count()
        );
    }

    /** Driver de API sem credenciais nao tenta enviar e explica o que falta. */
    #[Test]
    public function api_sem_credenciais_reporta_pendencia(): void
    {
        config(['whatsapp.driver' => 'cloud', 'whatsapp.cloud.token' => null, 'whatsapp.cloud.phone_id' => null]);
        $this->app->bind(DriverDeWhatsapp::class, fn () => new DriverCloudApi);

        Http::fake();

        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $this->post(route('mensagens.enviar-todas', $escala))->assertSessionHas('erro');

        Http::assertNothingSent();
    }

    /** Telefone invalido e registrado como erro, sem chamar a API. */
    #[Test]
    public function telefone_invalido_e_registrado_como_erro(): void
    {
        $escala = $this->escalaCompleta();
        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        $mensagem = EscalaMensagem::query()->where('escala_id', $escala->id)->first();
        $mensagem->update(['telefone' => '123']);

        app(EnviadorDeMensagens::class)->enviar($mensagem->fresh());

        $this->assertTrue($mensagem->fresh()->comErro());
        $this->assertStringContainsString('inválido', (string) $mensagem->fresh()->retorno);
    }

    // -----------------------------------------------------------------
    // Tela
    // -----------------------------------------------------------------

    #[Test]
    public function tela_de_mensagens_abre_e_prepara(): void
    {
        $escala = $this->escalaCompleta();

        $this->get(route('mensagens.index', $escala))
            ->assertOk()
            ->assertSee('Nenhuma mensagem preparada');

        $this->post(route('mensagens.preparar', $escala))->assertSessionHas('sucesso');

        $this->get(route('mensagens.index', $escala))
            ->assertOk()
            ->assertSee('Abrir WhatsApp');
    }

    /** Sem plantoes gerados nao ha o que comunicar. */
    #[Test]
    public function nao_prepara_sem_plantoes_gerados(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);

        $this->post(route('mensagens.preparar', $escala))->assertSessionHas('erro');
    }

    #[Test]
    public function driver_padrao_e_o_de_link(): void
    {
        config(['whatsapp.driver' => 'link']);

        $this->assertInstanceOf(DriverDeLink::class, app(DriverDeWhatsapp::class));
        $this->assertTrue(app(DriverDeWhatsapp::class)->requerAcaoManual());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * A mensagem diz de quem se recebe a ambulancia e para quem se entrega.
     *
     * A fila e circular: a posicao 1 recebe da ultima, e a ultima entrega para
     * a 1. E dai que sai, de graca, a resposta certa na virada do mes.
     */
    #[Test]
    public function a_mensagem_diz_o_revezamento_da_ambulancia(): void
    {
        $escala = $this->escalaCompleta();
        $posto = $escala->postos()->first();
        $vagas = $posto->vagas();

        $porPosicao = $posto->lotacoes()->with('motorista')->get()->keyBy('posicao');

        app(MontadorDeMensagens::class)->prepararParaEscala($escala);

        foreach ($porPosicao as $posicao => $lotacao) {
            $texto = EscalaMensagem::query()
                ->where('escala_id', $escala->id)
                ->where('motorista_id', $lotacao->motorista_id)
                ->firstOrFail()
                ->texto;

            $anterior = $porPosicao[(($posicao - 2 + $vagas) % $vagas) + 1]->motorista;
            $seguinte = $porPosicao[($posicao % $vagas) + 1]->motorista;

            $this->assertStringContainsString('Recebe de: '.$anterior->nome_curto, $texto);
            $this->assertStringContainsString('Entrega p/: '.$seguinte->nome_curto, $texto);

            // Ninguem recebe nem entrega para si mesmo.
            $this->assertStringNotContainsString('Recebe de: '.$lotacao->motorista->nome_curto, $texto);
        }
    }

    /**
     * Trocar alguem de posto deixava a mensagem antiga para tras.
     *
     * Preparar de novo so percorre quem TEM plantao, entao a mensagem de quem
     * saiu nunca era tocada: seguia na tela com as datas antigas, e abrir o
     * WhatsApp por ela mandaria escala para quem nao esta mais escalado.
     */
    #[Test]
    public function preparar_de_novo_descarta_a_mensagem_de_quem_saiu_da_escala(): void
    {
        $escala = $this->escalaCompleta();
        $montador = app(MontadorDeMensagens::class);
        $montador->prepararParaEscala($escala);

        $lotacao = $escala->lotacoes()->whereNotNull('escala_posto_id')->firstOrFail();
        $saiu = $lotacao->motorista_id;

        $this->assertTrue(
            EscalaMensagem::query()->where('escala_id', $escala->id)->where('motorista_id', $saiu)->exists()
        );

        // Sai do posto e vai para a reserva, como na troca real.
        app(MontadorDeEscala::class)->definirDestino($escala, $saiu, TipoDestino::Reserva);

        $montador->prepararParaEscala($escala->fresh());

        $this->assertFalse(
            EscalaMensagem::query()->where('escala_id', $escala->id)->where('motorista_id', $saiu)->exists(),
            'A mensagem de quem saiu da escala deveria ter sido descartada.'
        );
    }

    /**
     * A mensagem ja enviada de quem saiu NAO e apagada.
     *
     * Ela e o registro do que o motorista recebeu, e apagar esconderia
     * justamente o que o coordenador precisa saber: que alguem foi avisado de
     * plantoes que nao vai mais cumprir.
     */
    #[Test]
    public function mensagem_ja_enviada_de_quem_saiu_e_preservada_e_sinalizada(): void
    {
        $escala = $this->escalaCompleta();
        $montador = app(MontadorDeMensagens::class);
        $montador->prepararParaEscala($escala);

        $lotacao = $escala->lotacoes()->whereNotNull('escala_posto_id')->firstOrFail();
        $saiu = $lotacao->motorista_id;

        EscalaMensagem::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $saiu)
            ->update(['status' => EscalaMensagem::ENVIADA, 'enviada_em' => now()]);

        app(MontadorDeEscala::class)->definirDestino($escala, $saiu, TipoDestino::Reserva);
        $montador->prepararParaEscala($escala->fresh());

        $this->assertTrue(
            EscalaMensagem::query()->where('escala_id', $escala->id)->where('motorista_id', $saiu)->exists(),
            'A mensagem enviada deveria ter sido preservada.'
        );

        $this->assertContains($saiu, $montador->avisadosQueSairam($escala->fresh()));
    }

    private function escalaCompleta(int $descanso = 72): Escala
    {
        $unidade = Unidade::factory()->create([
            'sigla' => 'UPA',
            'nome' => 'UPA Centro',
            'horas_trabalho' => 24,
            'horas_descanso' => $descanso,
        ]);

        Ambulancia::factory()->create([
            'unidade_id' => $unidade->id,
            'placa' => 'THQ4H34',
            'identificacao' => 'UPA 1',
        ]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();

        $motoristas = Motorista::factory()->count($posto->vagas())->create();

        foreach ($motoristas->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        return $escala->fresh()->load(['lotacoes.motorista', 'lotacoes.posto.unidade', 'lotacoes.posto.ambulancia']);
    }
}
