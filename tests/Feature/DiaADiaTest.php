<?php

namespace Tests\Feature;

use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Whatsapp\PlantaoDoDia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * As consultas que o coordenador faz todo dia pelo celular: quem esta de
 * plantao (e o aviso para as unidades), os aniversariantes e o painel.
 */
class DiaADiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    // -----------------------------------------------------------------
    // Plantao do dia
    // -----------------------------------------------------------------

    #[Test]
    public function o_texto_traz_cada_ambulancia_com_motorista_e_telefone(): void
    {
        $escala = $this->escalaDeAgosto();
        $dia = Carbon::parse('2026-08-01');
        $plantao = EscalaPlantao::query()->whereDate('data', $dia)->with('motorista')->first();

        $texto = app(PlantaoDoDia::class)->texto($dia);

        $this->assertStringContainsString('*Sábado, 01/08/2026*', $texto);
        $this->assertStringContainsString('Das 07:00 de 01/08 às 07:00 de 02/08', $texto);
        $this->assertStringContainsString('*SEDE 1*', $texto);
        $this->assertStringContainsString(
            $plantao->motorista->nomePlanilha().' · '.$plantao->motorista->telefoneFormatado(),
            $texto
        );
        $this->assertSame(2, $escala->postos()->count());
    }

    /** A lista segue a ordem das ambulancias definida na montagem. */
    #[Test]
    public function o_texto_segue_a_ordem_das_ambulancias(): void
    {
        $escala = $this->escalaDeAgosto();
        $dia = Carbon::parse('2026-08-01');
        $plantao = app(PlantaoDoDia::class);

        $this->assertLessThan(strpos($plantao->texto($dia), '*GUANACES*'), strpos($plantao->texto($dia), '*SEDE 1*'));

        $guanaces = $escala->postos()->get()->first(fn ($p) => $p->rotuloLotacao() === 'GUANACES');
        app(MontadorDeEscala::class)->moverPosto($guanaces, -1);

        $this->assertLessThan(strpos($plantao->texto($dia), '*SEDE 1*'), strpos($plantao->texto($dia), '*GUANACES*'));
    }

    #[Test]
    public function o_sobreaviso_so_entra_quando_pedido(): void
    {
        $escala = $this->escalaDeAgosto();
        $reserva = Motorista::factory()->create(['nome_curto' => 'TIAGO RESERVA']);
        app(MontadorDeEscala::class)->definirDestino($escala, $reserva->id, TipoDestino::Reserva);

        $dia = Carbon::parse('2026-08-01');
        $plantao = app(PlantaoDoDia::class);

        $this->assertStringNotContainsString('TIAGO RESERVA', $plantao->texto($dia));
        $this->assertStringContainsString("*Sobreaviso*\nTIAGO RESERVA", $plantao->texto($dia, comSobreaviso: true));
    }

    /** Ambulancia sem ninguem no dia aparece na tela, para ser resolvida antes do envio. */
    #[Test]
    public function aponta_ambulancia_sem_plantonista_no_dia(): void
    {
        $escala = $this->escalaDeAgosto();
        $dia = Carbon::parse('2026-08-01');
        EscalaPlantao::query()->whereDate('data', $dia)->first()->delete();

        $this->assertCount(1, app(PlantaoDoDia::class)->semPlantonista($dia));

        $this->get(route('plantao', ['dia' => '2026-08-01']))
            ->assertOk()
            ->assertSee('está sem motorista neste dia');
    }

    #[Test]
    public function a_tela_do_plantao_mostra_a_lista_e_o_link_do_whatsapp(): void
    {
        $this->escalaDeAgosto();
        $plantao = EscalaPlantao::query()->whereDate('data', '2026-08-01')->with('motorista')->first();

        $this->get(route('plantao', ['dia' => '2026-08-01']))
            ->assertOk()
            ->assertSee($plantao->motorista->nome_curto)
            ->assertSee('https://wa.me/?text=', false)
            ->assertSee('Enviar pelo WhatsApp');
    }

    #[Test]
    public function data_invalida_no_endereco_mostra_hoje(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 10:00'));

        $this->get(route('plantao', ['dia' => 'ontem']))
            ->assertOk()
            ->assertSee('Segunda-feira, 10 de agosto de 2026');
    }

    // -----------------------------------------------------------------
    // Aniversariantes
    // -----------------------------------------------------------------

    #[Test]
    public function lista_os_aniversariantes_de_hoje_com_a_idade(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 09:00'));

        Motorista::factory()->create(['nome_curto' => 'ANIVERSARIANTE', 'data_nascimento' => '1980-10-29']);
        Motorista::factory()->create(['nome_curto' => 'AMANHA', 'data_nascimento' => '1980-10-30']);
        Motorista::factory()->inativo()->create(['nome_curto' => 'INATIVO', 'data_nascimento' => '1980-10-29']);

        $this->get(route('aniversariantes'))
            ->assertOk()
            ->assertSee('ANIVERSARIANTE')
            ->assertSee('46 anos')
            ->assertDontSee('INATIVO')
            ->assertDontSee('AMANHA');
    }

    /** Na ultima semana do ano, os de janeiro vem depois dos de dezembro. */
    #[Test]
    public function os_proximos_7_dias_atravessam_a_virada_do_ano(): void
    {
        $this->travelTo(Carbon::parse('2026-12-29 09:00'));

        Motorista::factory()->create(['nome_curto' => 'JANEIRO', 'data_nascimento' => '1990-01-02']);
        Motorista::factory()->create(['nome_curto' => 'DEZEMBRO', 'data_nascimento' => '1990-12-31']);
        Motorista::factory()->create(['nome_curto' => 'FORA', 'data_nascimento' => '1990-01-10']);

        $this->get(route('aniversariantes', ['periodo' => 'semana']))
            ->assertOk()
            ->assertSeeInOrder(['DEZEMBRO', 'JANEIRO'])
            ->assertSee('37 anos')
            ->assertDontSee('FORA');
    }

    #[Test]
    public function quem_nasceu_em_29_de_fevereiro_aparece_no_dia_28_em_ano_comum(): void
    {
        $this->travelTo(Carbon::parse('2027-02-28 09:00'));

        Motorista::factory()->create(['nome_curto' => 'BISSEXTO', 'data_nascimento' => '1988-02-29']);

        $this->get(route('aniversariantes'))->assertOk()->assertSee('BISSEXTO');
    }

    #[Test]
    public function mostra_os_aniversariantes_de_um_dia_escolhido_com_os_botoes(): void
    {
        Motorista::factory()->create([
            'nome_curto' => 'NOVEMBRO',
            'data_nascimento' => '1985-11-05',
            'telefone_1' => '85986926853',
        ]);

        $this->get(route('aniversariantes', ['dia' => '2026-11-05']))
            ->assertOk()
            ->assertSee('NOVEMBRO')
            ->assertSee('https://wa.me/5585986926853?text=', false)
            ->assertSee('tel:85986926853', false);
    }

    // -----------------------------------------------------------------
    // Painel
    // -----------------------------------------------------------------

    #[Test]
    public function o_painel_mostra_o_plantao_de_hoje_e_o_envio_para_as_unidades(): void
    {
        $this->escalaDeAgosto();
        $this->travelTo(Carbon::parse('2026-08-01 10:00'));
        $plantao = EscalaPlantao::query()->whereDate('data', '2026-08-01')->with('motorista')->first();

        $this->get(route('painel'))
            ->assertOk()
            ->assertSee('De plantão hoje')
            ->assertSee($plantao->motorista->nome_curto)
            ->assertSee('Enviar para as unidades pelo WhatsApp')
            ->assertSee('Aniversariantes')
            ->assertDontSee('Até as 07:00');
    }

    /** De madrugada, quem esta na ambulancia ainda e o plantao de ontem. */
    #[Test]
    public function antes_da_troca_o_painel_avisa_que_o_plantao_ainda_e_o_de_ontem(): void
    {
        $this->escalaDeAgosto();
        $this->travelTo(Carbon::parse('2026-08-02 05:30'));

        $this->get(route('painel'))
            ->assertOk()
            ->assertSee('ainda é o plantão de ontem')
            ->assertSee(route('plantao', ['dia' => '2026-08-01']), false);
    }

    #[Test]
    public function o_painel_oferece_criar_a_escala_do_mes_seguinte(): void
    {
        $this->escalaDeAgosto();
        $this->travelTo(Carbon::parse('2026-08-20 10:00'));

        $this->get(route('painel'))
            ->assertOk()
            ->assertSee('Setembro de 2026')
            ->assertSee('Ainda não foi criada')
            ->assertSee(route('escalas.create'), false);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Agosto/2026 com duas ambulancias 24/72 completas e plantoes gerados. */
    private function escalaDeAgosto(): Escala
    {
        $sede = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE', 'ordem' => 1]);
        $guanaces = Unidade::factory()->regime2472()->create(['sigla' => 'GUANACES', 'ordem' => 2]);
        Ambulancia::factory()->create(['unidade_id' => $sede->id, 'identificacao' => 'SEDE 1']);
        Ambulancia::factory()->create(['unidade_id' => $guanaces->id, 'identificacao' => 'GUANACES']);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);

        foreach ($escala->postos as $posto) {
            foreach (Motorista::factory()->count(4)->create(['telefone_1' => '85986926853'])->values() as $i => $motorista) {
                app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
            }
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        return $escala->fresh();
    }
}
