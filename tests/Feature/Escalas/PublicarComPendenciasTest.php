<?php

namespace Tests\Feature\Escalas;

use App\Enums\StatusEscala;
use App\Enums\StatusMotorista;
use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Escalas\ValidadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Publicar com pendencias: quem coordena pode publicar uma escala com um
 * descanso curto ou uma vaga aberta, desde que confirme e diga o motivo -- e
 * isso fica registrado na escala. Algumas pendencias nao se aceitam nunca.
 */
class PublicarComPendenciasTest extends TestCase
{
    use RefreshDatabase;

    private MontadorDeEscala $montador;

    private User $operador;

    private Unidade $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operador = User::factory()->create();
        $this->actingAs($this->operador);
        $this->montador = app(MontadorDeEscala::class);

        $this->unidade = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        Ambulancia::factory()->create(['unidade_id' => $this->unidade->id, 'identificacao' => 'SEDE 1']);
    }

    #[Test]
    public function sem_confirmar_as_pendencias_a_escala_nao_e_publicada(): void
    {
        $escala = $this->escalaComDescansoCurto();

        $this->post(route('escalas.publicar', $escala))
            ->assertSessionHas('erro', fn ($texto) => str_contains($texto, 'confirme as pendências e informe o motivo'));

        $this->assertSame(StatusEscala::Rascunho, $escala->fresh()->status);
    }

    #[Test]
    public function confirmar_sem_motivo_nao_basta(): void
    {
        $escala = $this->escalaComDescansoCurto();

        $this->post(route('escalas.publicar', $escala), ['aceitar_pendencias' => 1, 'justificativa' => 'ok'])
            ->assertSessionHasErrors('justificativa');

        $this->assertSame(StatusEscala::Rascunho, $escala->fresh()->status);
    }

    /** O caso do print: descanso curto na virada aceito pela coordenação. */
    #[Test]
    public function com_confirmacao_e_motivo_publica_e_registra_o_que_foi_aceito(): void
    {
        $escala = $this->escalaComDescansoCurto();

        $this->post(route('escalas.publicar', $escala), [
            'aceitar_pendencias' => 1,
            'justificativa' => 'Troca combinada com os motoristas; a coordenação acompanha.',
        ])->assertSessionHas('sucesso', fn ($texto) => str_contains($texto, 'pendência(s) aceita(s)'));

        $escala->refresh();
        $this->assertSame(StatusEscala::Publicada, $escala->status);
        $this->assertTrue($escala->publicadaComPendencias());
        $this->assertSame('Troca combinada com os motoristas; a coordenação acompanha.', $escala->justificativa_publicacao);
        $this->assertSame($this->operador->id, $escala->publicada_por);
        $this->assertContains('descanso_insuficiente', array_column($escala->pendencias_aceitas, 'codigo'));
        $this->assertNotEmpty($escala->pendencias_aceitas[0]['mensagem']);
    }

    /** O mesmo motorista em duas ambulâncias no mesmo dia não se aceita nunca. */
    #[Test]
    public function motorista_em_duas_ambulancias_no_mesmo_dia_sempre_bloqueia(): void
    {
        $escala = $this->escalaComMotoristaEmDoisLugares();

        $this->post(route('escalas.publicar', $escala), [
            'aceitar_pendencias' => 1,
            'justificativa' => 'Quero publicar assim mesmo.',
        ])->assertSessionHas('erro', fn ($texto) => str_contains($texto, 'precisa ser corrigida'));

        $this->assertSame(StatusEscala::Rascunho, $escala->fresh()->status);

        $this->get(route('escalas.show', $escala))
            ->assertOk()
            ->assertSee('Pendências que impedem a publicação')
            ->assertSee('precisa ser corrigida para publicar')
            ->assertDontSee('Estou ciente');
    }

    #[Test]
    public function escala_sem_plantoes_gerados_nao_tem_o_que_publicar(): void
    {
        $escala = $this->escalaMontada(gerar: false);

        $this->post(route('escalas.publicar', $escala), [
            'aceitar_pendencias' => 1,
            'justificativa' => 'Publicar antes de gerar.',
        ])->assertSessionHas('erro', fn ($texto) => str_contains($texto, 'não há o que publicar'));

        $this->assertSame(StatusEscala::Rascunho, $escala->fresh()->status);
    }

    /** Sem pendências, tudo como sempre foi: nada a confirmar, nada registrado. */
    #[Test]
    public function sem_pendencias_publica_como_antes_e_nao_registra_nada(): void
    {
        $escala = $this->escalaMontada();

        $this->post(route('escalas.publicar', $escala))->assertSessionHas('sucesso');

        $escala->refresh();
        $this->assertSame(StatusEscala::Publicada, $escala->status);
        $this->assertFalse($escala->publicadaComPendencias());
        $this->assertNull($escala->justificativa_publicacao);
    }

    #[Test]
    public function republicar_sem_pendencias_limpa_o_registro_anterior(): void
    {
        $escala = $this->escalaComDescansoCurto();
        $this->montador->publicar($escala, pendenciasAceitas: [['codigo' => 'descanso_insuficiente', 'mensagem' => 'x']], justificativa: 'Motivo da primeira vez.');

        $this->post(route('escalas.reabrir', $escala));
        $this->montador->publicar($escala->fresh());

        $this->assertNull($escala->fresh()->pendencias_aceitas);
        $this->assertNull($escala->fresh()->justificativa_publicacao);
    }

    #[Test]
    public function a_tela_oferece_publicar_com_pendencias_e_depois_mostra_o_registro(): void
    {
        $escala = $this->escalaComDescansoCurto();

        $this->get(route('escalas.show', $escala))
            ->assertOk()
            ->assertSee('Pendências antes de publicar')
            ->assertSee('Publicar escala com pendências')
            ->assertSee('Estou ciente')
            ->assertSee('Motivo (obrigatório)')
            ->assertSee('Publicar mesmo assim');

        $this->post(route('escalas.publicar', $escala), [
            'aceitar_pendencias' => 1,
            'justificativa' => 'Troca combinada entre os motoristas.',
        ]);

        $this->get(route('escalas.show', $escala))
            ->assertOk()
            ->assertSee('Publicada com')
            ->assertSee('por '.$this->operador->nome)
            ->assertSee('Troca combinada entre os motoristas.')
            // As aceitas ficam só no quadro do registro, não no cartão vermelho.
            ->assertDontSee('Pendências desta escala');

        // Aceitar as pendências é decisão tomada: a lista não marca a escala,
        // o registro fica só no quadro da própria escala.
        $this->get(route('escalas.index'))->assertOk()->assertDontSee('com pendências');
    }

    /**
     * O que foi aceito sai do cartão vermelho; o que aparecer depois da
     * publicação continua nele -- e reabrindo, as aceitas voltam, porque
     * publicar de novo exige aceitar de novo.
     */
    #[Test]
    public function so_as_pendencias_novas_aparecem_no_cartao_da_escala_publicada(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10 10:00'));
        $escala = $this->escalaComDescansoCurto();
        $aceita = ValidadorDeEscala::para($escala)->erros()[0]->mensagem;

        $this->post(route('escalas.publicar', $escala), [
            'aceitar_pendencias' => 1,
            'justificativa' => 'Troca combinada entre os motoristas.',
        ]);

        // Depois de publicada, um motorista escalado é inativado no cadastro.
        $inativado = $escala->lotacoes()->whereNotNull('escala_posto_id')->orderByDesc('posicao')->first()->motorista;
        $inativado->update(['status' => StatusMotorista::Inativo]);

        $resposta = $this->get(route('escalas.show', $escala))->assertOk()->assertSee('Pendências desta escala');
        $this->assertSame(
            1,
            collect($resposta->viewData('alertas'))->filter(fn ($a) => $a->ehErro())->count(),
            'Só a pendência nova, uma vez só, deveria estar no cartão.'
        );
        $this->assertSame('motorista_inativo_escalado', collect($resposta->viewData('alertas'))->first(fn ($a) => $a->ehErro())->codigo);

        $this->post(route('escalas.reabrir', $escala));

        $this->assertContains(
            $aceita,
            collect($this->get(route('escalas.show', $escala))->viewData('alertas'))->pluck('mensagem')->all()
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Agosto/2026: uma ambulância completa, um motorista na reserva, plantões gerados. */
    private function escalaMontada(bool $gerar = true): Escala
    {
        $escala = $this->montador->criar(2026, 8, copiarMesAnterior: false);
        $posto = $escala->postos()->first();
        $motoristas = Motorista::factory()->count(5)->create()->values();

        foreach ($motoristas->take(4) as $i => $motorista) {
            $this->montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        $this->montador->definirDestino($escala, $motoristas[4]->id, TipoDestino::Reserva);

        if ($gerar) {
            app(GeradorDeEscala::class)->gerar($escala->fresh());
        }

        return $escala->fresh();
    }

    /** Uma troca manual deixa o mesmo motorista nos dias 1º e 2: descanso curto. */
    private function escalaComDescansoCurto(): Escala
    {
        $escala = $this->escalaMontada();
        $dia1 = $escala->plantoes()->whereDate('data', '2026-08-01')->first();

        $escala->plantoes()->whereDate('data', '2026-08-02')->first()
            ->update(['motorista_id' => $dia1->motorista_id, 'ajuste_manual' => true]);

        return $escala->fresh();
    }

    /** Duas ambulâncias, com o mesmo motorista nas duas no dia 1º. */
    private function escalaComMotoristaEmDoisLugares(): Escala
    {
        $escala = $this->escalaMontada();
        $segunda = Ambulancia::factory()->create(['unidade_id' => $this->unidade->id, 'identificacao' => 'SEDE 2']);
        $posto = $this->montador->adicionarPosto($escala, $this->unidade->id, $segunda->id);

        foreach (Motorista::factory()->count(4)->create()->values() as $i => $motorista) {
            $this->montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $primeiro = EscalaPlantao::query()->where('escala_id', $escala->id)->whereDate('data', '2026-08-01')->orderBy('id')->first();
        EscalaPlantao::query()
            ->where('escala_posto_id', $posto->id)
            ->whereDate('data', '2026-08-01')
            ->update(['motorista_id' => $primeiro->motorista_id, 'ajuste_manual' => true]);

        return $escala->fresh();
    }
}
