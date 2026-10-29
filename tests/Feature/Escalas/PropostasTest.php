<?php

namespace Tests\Feature\Escalas;

use App\Enums\StatusEscala;
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
use App\Services\Whatsapp\PlantaoDoDia;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Propostas alternativas de escala para o mesmo mes, enquanto os superiores
 * decidem. A regra que atravessa tudo: proposta sem decisao nao aparece fora
 * da propria tela -- nem no plantao do dia, nem nas mensagens, nem na rotacao
 * do mes seguinte.
 */
class PropostasTest extends TestCase
{
    use RefreshDatabase;

    private MontadorDeEscala $montador;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operador = User::factory()->create();
        $this->actingAs($this->operador);
        $this->montador = app(MontadorDeEscala::class);

        $unidade = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        Ambulancia::factory()->create(['unidade_id' => $unidade->id, 'identificacao' => 'SEDE 1']);
        Motorista::factory()->count(5)->create();
    }

    // -----------------------------------------------------------------
    // Criar e duplicar
    // -----------------------------------------------------------------

    #[Test]
    public function o_mes_aceita_varias_propostas_e_nomeia_cada_uma(): void
    {
        $primeira = $this->montador->criar(2026, 8);
        $this->assertNull($primeira->nome, 'A escala única do mês não precisa de nome.');

        $segunda = $this->montador->criar(2026, 8, copiarMesAnterior: false);

        $this->assertSame('Proposta A', $primeira->fresh()->nome);
        $this->assertSame('Proposta B', $segunda->nome);
        $this->assertSame('Agosto de 2026 · Proposta B', $segunda->titulo());
    }

    #[Test]
    public function mes_com_escala_publicada_nao_recebe_proposta_nova(): void
    {
        $escala = $this->escalaMontada();
        $this->montador->publicar($escala);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reabrir para ajustes');

        $this->montador->criar(2026, 8);
    }

    /** A alternativa nasce igual -- equipes, destinos, plantões e trocas -- e muda só o que difere. */
    #[Test]
    public function duplicar_copia_a_proposta_inteira(): void
    {
        $original = $this->escalaMontada();
        $reserva = $original->lotacoes()->where('tipo_destino', TipoDestino::Reserva)->first()->motorista;
        $this->montador->definirDestino($original, $reserva->id, TipoDestino::Reserva, observacao: 'Cobre férias');

        $troca = $original->plantoes()->orderBy('data')->first();
        $troca->update(['ajuste_manual' => true, 'observacao' => 'Troca combinada']);

        $copia = $this->montador->duplicar($original->fresh());

        $this->assertSame('Proposta A', $original->fresh()->nome);
        $this->assertSame('Proposta B', $copia->nome);
        $this->assertSame($original->id, $copia->origem_id);
        $this->assertSame($original->postos()->count(), $copia->postos()->count());
        $this->assertSame($original->lotacoes()->count(), $copia->lotacoes()->count());
        $this->assertSame($original->plantoes()->count(), $copia->plantoes()->count());

        // Os plantões apontam para os postos da cópia, não para os da original.
        $this->assertSame(
            $copia->postos()->pluck('id')->sort()->values()->all(),
            $copia->plantoes()->distinct()->pluck('escala_posto_id')->sort()->values()->all(),
        );

        $this->assertTrue($copia->plantoes()->where('ajuste_manual', true)->where('observacao', 'Troca combinada')->exists());
        $this->assertSame('Cobre férias', $copia->lotacoes()->where('motorista_id', $reserva->id)->value('observacao'));
    }

    // -----------------------------------------------------------------
    // Escala vigente do mes
    // -----------------------------------------------------------------

    #[Test]
    public function a_vigente_e_a_unica_proposta_ou_a_publicada(): void
    {
        $a = $this->escalaMontada();
        $this->assertTrue(Escala::vigenteDoMes(2026, 8)->is($a));
        $this->assertTrue(Escala::propostasSemDecisao(2026, 8)->isEmpty());

        $b = $this->montador->duplicar($a);
        $this->assertNull(Escala::vigenteDoMes(2026, 8));
        $this->assertCount(2, Escala::propostasSemDecisao(2026, 8));

        $this->montador->publicar($b, excluirOutras: false);
        $this->assertTrue(Escala::vigenteDoMes(2026, 8)->is($b));
        $this->assertTrue(Escala::propostasSemDecisao(2026, 8)->isEmpty());
    }

    /** A garantia de uma oficial por mês está no banco, não só na tela. */
    #[Test]
    public function o_banco_recusa_duas_escalas_oficiais_no_mesmo_mes(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);
        $this->montador->publicar($a, excluirOutras: false);

        $this->expectException(UniqueConstraintViolationException::class);

        $b->fresh()->update(['status' => StatusEscala::Publicada]);
    }

    // -----------------------------------------------------------------
    // Publicar uma proposta
    // -----------------------------------------------------------------

    #[Test]
    public function publicar_exclui_as_outras_propostas(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);

        $this->post(route('escalas.publicar', $a), ['outras' => 'excluir'])
            ->assertRedirect(route('documentos.index', $a))
            ->assertSessionHas('sucesso', fn ($texto) => str_contains($texto, 'foram excluídas'));

        $this->assertSame(StatusEscala::Publicada, $a->fresh()->status);
        $this->assertNull(Escala::query()->find($b->id));
        $this->assertSame(0, EscalaPlantao::query()->where('escala_id', $b->id)->count());
    }

    #[Test]
    public function publicar_pode_guardar_as_outras_como_descartadas_e_registra_a_aprovacao(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);

        $this->post(route('escalas.publicar', $b), [
            'outras' => 'descartar',
            'aprovada_por' => 'Secretária de Saúde',
            'aprovada_em' => '2026-07-28',
        ])->assertSessionHas('sucesso');

        $b->refresh();
        $this->assertSame(StatusEscala::Publicada, $b->status);
        $this->assertSame('Secretária de Saúde', $b->aprovada_por);
        $this->assertSame('2026-07-28', $b->aprovada_em->toDateString());
        $this->assertSame($this->operador->id, $b->publicada_por);
        $this->assertSame(StatusEscala::Descartada, $a->fresh()->status);
    }

    /** Descartada volta a rascunho só enquanto o mês não tem publicada. */
    #[Test]
    public function descartada_volta_a_rascunho_so_sem_publicada_no_mes(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);
        $this->montador->publicar($a, excluirOutras: false);

        $this->post(route('escalas.restaurar', $b))->assertSessionHas('erro');
        $this->assertSame(StatusEscala::Descartada, $b->fresh()->status);

        $this->post(route('escalas.reabrir', $a));
        $this->post(route('escalas.restaurar', $b))->assertSessionHas('sucesso');
        $this->assertSame(StatusEscala::Rascunho, $b->fresh()->status);
    }

    #[Test]
    public function renomeia_a_proposta_sem_repetir_nome_no_mes(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);

        $this->put(route('escalas.renomear', $b), ['nome' => 'Com 2 contratações', 'observacao' => 'Depende do edital'])
            ->assertSessionHas('sucesso');
        $this->assertSame('Com 2 contratações', $b->fresh()->nome);
        $this->assertSame('Depende do edital', $b->fresh()->observacao);

        $this->put(route('escalas.renomear', $a), ['nome' => 'Com 2 contratações'])->assertSessionHasErrors('nome');
    }

    #[Test]
    public function duplicar_pela_tela_abre_a_nova_proposta(): void
    {
        $a = $this->escalaMontada();

        $resposta = $this->post(route('escalas.duplicar', $a), ['nome' => 'Sem a SEDE 4']);

        $nova = Escala::query()->where('nome', 'Sem a SEDE 4')->firstOrFail();
        $resposta->assertRedirect(route('escalas.show', $nova));
    }

    #[Test]
    public function consulta_nao_cria_nem_publica_propostas(): void
    {
        $a = $this->escalaMontada();
        $this->actingAs(User::factory()->leitor()->create());

        $this->post(route('escalas.duplicar', $a))->assertForbidden();
        $this->post(route('escalas.publicar', $a))->assertForbidden();
        $this->assertSame(1, Escala::query()->count());
    }

    // -----------------------------------------------------------------
    // Telas
    // -----------------------------------------------------------------

    #[Test]
    public function a_tela_da_proposta_alterna_entre_as_alternativas_e_pergunta_ao_publicar(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);

        $this->get(route('escalas.show', $b))
            ->assertOk()
            ->assertSee('Escala de Agosto de 2026 · Proposta B')
            ->assertSee('Propostas de agosto de 2026')
            ->assertSee(route('escalas.show', $a), false)
            ->assertSee(route('escalas.comparar', $b), false)
            ->assertSee('Publicar esta proposta')
            ->assertSee('Guardar como descartada')
            ->assertSee('Aprovada por (opcional)')
            ->assertSee('Criar outra proposta a partir desta')
            // Mensagens só depois de publicar.
            ->assertDontSee(route('mensagens.index', $b), false);

        $this->get(route('escalas.index'))->assertOk()->assertSee('2 propostas para este mês');
    }

    #[Test]
    public function a_descartada_avisa_e_a_publicada_mostra_quem_aprovou(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);
        $this->montador->publicar($a, excluirOutras: false, aprovadaPor: 'Secretária de Saúde', aprovadaEm: Carbon::parse('2026-07-28'));

        $this->get(route('escalas.show', $b))
            ->assertOk()
            ->assertSee('Esta proposta foi descartada')
            ->assertDontSee('Publicar esta proposta')
            // O mês já tem publicada: não há volta para rascunho.
            ->assertDontSee('Voltar para rascunho');

        $this->get(route('escalas.show', $a))
            ->assertOk()
            ->assertSee('Aprovada por')
            ->assertSee('Secretária de Saúde')
            ->assertSee('28/07/2026')
            ->assertSee(route('mensagens.index', $a), false);
    }

    // -----------------------------------------------------------------
    // Rotacao: so a escala que valeu conta
    // -----------------------------------------------------------------

    /**
     * O risco mais grave: a fila de outubro continuar da proposta descartada
     * de setembro. Aqui a descartada reinicia a fila no dia 1º, e por isso
     * termina o mês noutra posição -- se fosse a âncora, outubro mudaria.
     */
    #[Test]
    public function a_rotacao_continua_da_proposta_publicada_e_nao_da_descartada(): void
    {
        $agosto = $this->escalaMontada(8);
        $this->montador->publicar($agosto);

        $setembroA = $this->montador->criar(2026, 9);
        app(GeradorDeEscala::class)->gerar($setembroA->fresh());

        $setembroB = $this->montador->duplicar($setembroA->fresh());
        $setembroB->postos()->update(['continuar_rotacao' => false]);
        app(GeradorDeEscala::class)->gerar($setembroB->fresh());

        $ultimoA = $setembroA->plantoes()->whereDate('data', '2026-09-30')->value('posicao');
        $ultimoB = $setembroB->plantoes()->whereDate('data', '2026-09-30')->value('posicao');
        $this->assertNotSame($ultimoA, $ultimoB, 'O teste precisa de propostas que terminem setembro em posições diferentes.');

        $this->montador->publicar($setembroA, excluirOutras: false);

        $outubro = $this->montador->criar(2026, 10);
        app(GeradorDeEscala::class)->gerar($outubro->fresh());

        $this->assertSame($ultimoA % 4 + 1, $outubro->plantoes()->whereDate('data', '2026-10-01')->value('posicao'));
        $this->assertNotContains(
            'descanso_insuficiente',
            array_map(fn ($a) => $a->codigo, ValidadorDeEscala::para($outubro->fresh())->validar())
        );
    }

    #[Test]
    public function nao_gera_o_mes_seguinte_enquanto_o_anterior_esta_indefinido(): void
    {
        $agosto = $this->escalaMontada(8);
        $this->montador->duplicar($agosto);

        // Copiar de agosto seria escolher uma das propostas sozinho.
        try {
            $this->montador->criar(2026, 9);
            $this->fail('Deveria recusar copiar de um mês com propostas sem decisão.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('2 propostas e nenhuma foi publicada', $e->getMessage());
        }

        $setembro = $this->montador->criar(2026, 9, copiarMesAnterior: false);
        $resultado = app(GeradorDeEscala::class)->gerar($setembro->fresh());

        $this->assertTrue($resultado->temErros());
        $this->assertSame('mes_anterior_sem_decisao', $resultado->erros()[0]->codigo);
        $this->assertSame(0, $setembro->plantoes()->count());
    }

    // -----------------------------------------------------------------
    // Nada de proposta fora da propria tela
    // -----------------------------------------------------------------

    #[Test]
    public function com_duas_propostas_o_plantao_do_dia_so_avisa(): void
    {
        $a = $this->escalaMontada();
        $this->montador->duplicar($a);
        $dia = Carbon::parse('2026-08-01');

        $this->assertTrue(app(PlantaoDoDia::class)->plantoes($dia)->isEmpty());

        $this->get(route('plantao', ['dia' => '2026-08-01']))
            ->assertOk()
            ->assertSee('Agosto tem 2 propostas e nenhuma foi publicada')
            ->assertDontSee('Enviar pelo WhatsApp');

        $this->travelTo(Carbon::parse('2026-08-01 10:00'));
        $this->get(route('painel'))
            ->assertOk()
            ->assertSee('Agosto tem 2 propostas e nenhuma foi publicada')
            ->assertDontSee('Enviar para as unidades pelo WhatsApp');
    }

    #[Test]
    public function publicada_uma_o_plantao_do_dia_mostra_so_ela(): void
    {
        $a = $this->escalaMontada();
        $b = $this->montador->duplicar($a);
        $this->montador->publicar($b, excluirOutras: false);

        $plantoes = app(PlantaoDoDia::class)->plantoes(Carbon::parse('2026-08-01'));

        $this->assertCount(1, $plantoes);
        $this->assertSame($b->id, $plantoes->first()->escala_id);
    }

    #[Test]
    public function mensagens_aos_motoristas_so_depois_de_publicar(): void
    {
        $escala = $this->escalaMontada();

        $this->post(route('mensagens.preparar', $escala))->assertSessionHas('erro');
        $this->assertSame(0, $escala->mensagens()->count());

        $this->get(route('mensagens.index', $escala))->assertOk()->assertSee('Esta escala ainda não foi publicada');

        $this->montador->publicar($escala);

        $this->post(route('mensagens.preparar', $escala))->assertSessionHas('sucesso');
        $this->assertSame(4, $escala->mensagens()->count());
    }

    /** O cadastro do motorista não mostra dias de proposta que podem não acontecer. */
    #[Test]
    public function os_proximos_plantoes_do_motorista_vem_so_da_escala_vigente(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 10:00'));
        $a = $this->escalaMontada();
        $motorista = $a->lotacoes()->whereNotNull('escala_posto_id')->first()->motorista;
        $b = $this->montador->duplicar($a);

        $this->get(route('motoristas.show', $motorista))->assertOk()->assertViewHas('proximosPlantoes', fn ($p) => $p->isEmpty());

        $this->montador->publicar($b, excluirOutras: false);

        $this->get(route('motoristas.show', $motorista))
            ->assertOk()
            ->assertViewHas('proximosPlantoes', fn ($p) => $p->isNotEmpty() && $p->every(fn ($x) => $x->escala_id === $b->id));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Escala do mês com a ambulância completa (4 motoristas) e plantões gerados. */
    private function escalaMontada(int $mes = 8): Escala
    {
        $escala = $this->montador->criar(2026, $mes, copiarMesAnterior: false);
        $posto = $escala->postos()->first();

        $motoristas = Motorista::query()->orderBy('id')->get()->values();

        foreach ($motoristas->take(4) as $i => $motorista) {
            $this->montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        // O quinto vai para a reserva: todo o efetivo com destino, como a
        // publicação exige.
        $this->montador->definirDestino($escala, $motoristas[4]->id, TipoDestino::Reserva);

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        return $escala->fresh();
    }
}
