<?php

namespace Tests\Feature\Escalas;

use App\Enums\StatusEscala;
use App\Enums\TipoDestino;
use App\Livewire\DefinirDestinos;
use App\Livewire\MontarEscala;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Documentos\GeradorDeDocumentos;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fluxo de ponta a ponta da escala mensal: criar, montar, definir destinos,
 * gerar plantoes e publicar.
 */
class FluxoDaEscalaTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operador = User::factory()->admin()->create();
        $this->actingAs($this->operador);
    }

    // -----------------------------------------------------------------
    // Telas
    // -----------------------------------------------------------------

    #[Test]
    public function abre_as_telas_da_escala(): void
    {
        $escala = $this->escalaMontada();

        $this->get(route('escalas.index'))->assertOk();
        $this->get(route('escalas.create'))->assertOk();
        $this->get(route('escalas.show', $escala))->assertOk();
        $this->get(route('escalas.montar', $escala))->assertOk();
        $this->get(route('escalas.destinos', $escala))->assertOk();
    }

    /** A planilha na tela mostra o X no dia de cada motorista. */
    #[Test]
    public function planilha_mostra_os_plantoes_gerados(): void
    {
        $escala = $this->escalaMontada();
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $primeiro = $escala->plantoes()->orderBy('data')->first();

        $this->get(route('escalas.show', $escala))
            ->assertOk()
            ->assertSee($primeiro->motorista->nomePlanilha())
            ->assertSee($escala->postos()->first()->rotuloPlaca());
    }

    // -----------------------------------------------------------------
    // Criação
    // -----------------------------------------------------------------

    #[Test]
    public function cria_escala_montando_os_postos_da_frota(): void
    {
        $upa = Unidade::factory()->regime2472()->create(['sigla' => 'UPA']);
        $praia = Unidade::factory()->regime2448()->create(['sigla' => 'PRAIA']);
        Ambulancia::factory()->create(['unidade_id' => $upa->id]);
        Ambulancia::factory()->create(['unidade_id' => $praia->id]);
        Motorista::factory()->count(9)->create();

        $this->post(route('escalas.store'), ['ano' => 2026, 'mes' => 8])
            ->assertRedirect();

        $escala = Escala::query()->doMes(2026, 8)->firstOrFail();

        $this->assertCount(2, $escala->postos);
        // 24/72 (4) + 24/48 (3) = 7 vagas.
        $this->assertSame(7, $escala->postos->sum(fn ($p) => $p->vagas()));
        // Todo motorista ativo entra na folha de lotacao do mes.
        $this->assertSame(9, $escala->lotacoes()->count());
    }

    #[Test]
    public function nao_cria_duas_escalas_para_o_mesmo_mes(): void
    {
        Unidade::factory()->create();

        $this->post(route('escalas.store'), ['ano' => 2026, 'mes' => 8]);

        $this->post(route('escalas.store'), ['ano' => 2026, 'mes' => 8])
            ->assertSessionHas('erro');

        $this->assertSame(1, Escala::query()->count());
    }

    // -----------------------------------------------------------------
    // Montagem (Livewire)
    // -----------------------------------------------------------------

    #[Test]
    public function lota_motorista_em_uma_posicao_do_ciclo(): void
    {
        $escala = $this->escalaVazia();
        $posto = $escala->postos()->first();
        $motorista = Motorista::factory()->create();

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('lotar', $posto->id, 2, $motorista->id)
            ->assertHasNoErrors();

        $lotacao = EscalaLotacao::query()->where('motorista_id', $motorista->id)->firstOrFail();

        $this->assertSame($posto->id, $lotacao->escala_posto_id);
        $this->assertSame(2, $lotacao->posicao);
    }

    #[Test]
    public function libera_a_posicao_ao_escolher_vaga_aberta(): void
    {
        $escala = $this->escalaVazia();
        $posto = $escala->postos()->first();
        $motorista = Motorista::factory()->create();

        app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, 1);

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('lotar', $posto->id, 1, null);

        $this->assertNull(
            EscalaLotacao::query()->where('motorista_id', $motorista->id)->first()->escala_posto_id
        );
    }

    /** Reordenar troca o dia em que cada motorista pega plantao. */
    #[Test]
    public function move_motorista_entre_posicoes(): void
    {
        $escala = $this->escalaVazia();
        $posto = $escala->postos()->first();
        $primeiro = Motorista::factory()->create();
        $segundo = Motorista::factory()->create();

        $montador = app(MontadorDeEscala::class);
        $montador->lotarMotorista($posto, $primeiro->id, 1);
        $montador->lotarMotorista($posto, $segundo->id, 2);

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('mover', $posto->id, 1, 1);

        $this->assertSame(2, EscalaLotacao::query()->where('motorista_id', $primeiro->id)->first()->posicao);
        $this->assertSame(1, EscalaLotacao::query()->where('motorista_id', $segundo->id)->first()->posicao);
    }

    #[Test]
    public function adiciona_posto_pela_tela_de_montagem(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        $ambulancia = Ambulancia::factory()->create(['unidade_id' => $unidade->id]);
        $escala = app(MontadorDeEscala::class)->criar(2026, 8, copiarMesAnterior: false);

        // A escala nasceu com o posto da frota; removemos para testar a adicao.
        $escala->postos()->delete();

        Livewire::test(MontarEscala::class, ['escala' => $escala->fresh()])
            ->set('novaAmbulanciaId', $ambulancia->id)
            ->set('novaUnidadeId', $unidade->id)
            ->call('adicionarPosto')
            ->assertHasNoErrors();

        $this->assertSame(1, $escala->fresh()->postos()->count());
    }

    #[Test]
    public function recusa_a_mesma_ambulancia_em_dois_postos_na_tela(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        $ambulancia = Ambulancia::factory()->create(['unidade_id' => $unidade->id]);
        $escala = app(MontadorDeEscala::class)->criar(2026, 8, copiarMesAnterior: false);

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->set('novaAmbulanciaId', $ambulancia->id)
            ->set('novaUnidadeId', $unidade->id)
            ->call('adicionarPosto')
            ->assertHasErrors('novaAmbulanciaId');
    }

    /**
     * Remanejar o posto para uma unidade de regime menor libera as posicoes que
     * passaram do novo ciclo, que de outro modo ficariam sem gerar plantao.
     */
    #[Test]
    public function remanejar_para_regime_menor_libera_posicoes_excedentes(): void
    {
        $upa = Unidade::factory()->regime2472()->create(['sigla' => 'UPA']);   // 4 vagas
        $praia = Unidade::factory()->regime2448()->create(['sigla' => 'PRAIA']); // 3 vagas
        Ambulancia::factory()->create(['unidade_id' => $upa->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();

        $motoristas = Motorista::factory()->count(4)->create();
        foreach ($motoristas->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        Livewire::test(MontarEscala::class, ['escala' => $escala->fresh()])
            ->call('alterarUnidadeDoPosto', $posto->id, $praia->id);

        $posto->refresh();

        $this->assertSame('24/48', $posto->regimeNotacao());
        $this->assertSame(3, $posto->vagas());
        // O motorista da posicao 4 voltou para a lista de disponiveis.
        $this->assertSame(3, $posto->lotacoes()->count());
        $this->assertNull(
            EscalaLotacao::query()->where('motorista_id', $motoristas[3]->id)->first()->escala_posto_id
        );
    }

    #[Test]
    public function preenche_vagas_pela_tela(): void
    {
        $escala = $this->escalaVazia();
        Motorista::factory()->count(4)->create();

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('preencherAutomaticamente');

        $this->assertSame(0, $escala->fresh()->load('postos.lotacoes')->postos->sum(fn ($p) => $p->vagasLivres()));
    }

    /**
     * Requisito de operacao parcial: a ambulancia entregue no meio do mes so
     * gera plantao a partir da data informada.
     */
    #[Test]
    public function define_o_periodo_de_operacao_do_posto(): void
    {
        $escala = $this->escalaMontada();
        $posto = $escala->postos()->first();

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('alterarVigencia', $posto->id, 'data_inicio', '2026-08-04');

        $posto->refresh();

        $this->assertSame('2026-08-04', $posto->data_inicio->toDateString());

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        // Do dia 04 ao 31 são 28 dias.
        $this->assertSame(28, $escala->fresh()->plantoes()->count());
        $this->assertSame(
            '2026-08-04',
            $escala->fresh()->plantoes()->orderBy('data')->first()->data->toDateString()
        );
    }

    /** Os campos de período aparecem ao expandir o posto. */
    #[Test]
    public function painel_do_posto_mostra_os_campos_de_periodo(): void
    {
        $escala = $this->escalaMontada();
        $posto = $escala->postos()->first();

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->assertDontSee('Período de operação no mês')
            ->call('abrirPosto', $posto->id)
            ->assertSee('Período de operação no mês')
            ->assertSee('Primeiro dia de plantão')
            ->assertSee('Último dia de plantão')
            ->assertSee('Remanejar para outra unidade');
    }

    /** Data fora do mês da escala deixaria o posto sem nenhum plantão. */
    #[Test]
    public function recusa_vigencia_fora_do_mes(): void
    {
        $escala = $this->escalaMontada();
        $posto = $escala->postos()->first();

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('alterarVigencia', $posto->id, 'data_inicio', '2026-09-15')
            ->assertDispatched('aviso', tipo: 'erro');

        $this->assertNull($posto->fresh()->data_inicio);
    }

    #[Test]
    public function recusa_termino_antes_do_inicio(): void
    {
        $escala = $this->escalaMontada();
        $posto = $escala->postos()->first();
        $posto->update(['data_inicio' => '2026-08-10']);

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('alterarVigencia', $posto->id, 'data_fim', '2026-08-05')
            ->assertDispatched('aviso', tipo: 'erro');

        $this->assertNull($posto->fresh()->data_fim);
    }

    /** Limpar o campo devolve o posto ao mês inteiro. */
    #[Test]
    public function limpar_a_vigencia_volta_ao_mes_inteiro(): void
    {
        $escala = $this->escalaMontada();
        $posto = $escala->postos()->first();
        $posto->update(['data_inicio' => '2026-08-10']);

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('alterarVigencia', $posto->id, 'data_inicio', '');

        $this->assertNull($posto->fresh()->data_inicio);

        app(GeradorDeEscala::class)->gerar($escala->fresh());
        $this->assertSame(31, $escala->fresh()->plantoes()->count());
    }

    #[Test]
    public function alterna_a_rotacao_continua_do_posto(): void
    {
        $escala = $this->escalaVazia();
        $posto = $escala->postos()->first();

        $this->assertTrue($posto->continuar_rotacao);

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('alternarRotacao', $posto->id);

        $this->assertFalse($posto->fresh()->continuar_rotacao);
    }

    // -----------------------------------------------------------------
    // Destinos (Livewire)
    // -----------------------------------------------------------------

    #[Test]
    public function define_destino_de_motorista_que_sobrou(): void
    {
        $escala = $this->escalaMontada();
        $sobra = Motorista::factory()->create();
        app(MontadorDeEscala::class)->sincronizarEfetivo($escala);

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('definir', $sobra->id, TipoDestino::Ferias->value);

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $sobra->id)
            ->firstOrFail();

        $this->assertSame(TipoDestino::Ferias, $lotacao->tipo_destino);
    }

    #[Test]
    public function manda_todos_os_pendentes_para_reserva(): void
    {
        $escala = $this->escalaMontada();
        Motorista::factory()->count(3)->create();

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('todosParaReserva');

        $reservas = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('tipo_destino', TipoDestino::Reserva)
            ->count();

        $this->assertSame(3, $reservas);
    }

    /**
     * O texto da ocorrencia e montado a partir do periodo quando o operador nao
     * escreve nada, o que alimenta a coluna OCORRENCIA da lista mensal.
     */
    #[Test]
    public function monta_o_texto_da_ocorrencia_a_partir_do_periodo(): void
    {
        $escala = $this->escalaMontada();
        $motorista = Motorista::factory()->create();

        app(MontadorDeEscala::class)->definirDestino($escala, $motorista->id, TipoDestino::Ferias);

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $motorista->id)
            ->firstOrFail();

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->set('periodoInicio', '2026-08-01')
            ->set('periodoFim', '2026-08-30')
            ->call('salvarDetalhes')
            ->assertHasNoErrors();

        $this->assertSame('Férias de 01 a 30/08/26', $lotacao->fresh()->textoOcorrencia());
    }

    /**
     * Motorista escalado que teve uma falta continua na escala, mas a ocorrência
     * precisa constar na lista mensal enviada ao RH.
     */
    #[Test]
    public function registra_ocorrencia_de_motorista_escalado(): void
    {
        $escala = $this->escalaMontada();
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        $plantoesAntes = $lotacao->plantoes_previstos;

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->set('observacao', 'Falta em 12/08')
            ->call('salvarDetalhes')
            ->assertHasNoErrors();

        $lotacao->refresh();

        $this->assertSame('Falta em 12/08', $lotacao->textoOcorrencia());

        // Continua escalado, com os mesmos plantões.
        $this->assertTrue($lotacao->escalado());
        $this->assertSame($plantoesAntes, $lotacao->plantoes_previstos);

        // E a ocorrência chega ao documento.
        $linha = app(GeradorDeDocumentos::class)
            ->linhasDeOcorrencias($escala->fresh())
            ->firstWhere('nome', $lotacao->motorista->nomeDocumento());

        $this->assertSame('Falta em 12/08', $linha['ocorrencia']);
    }

    /**
     * Sem tipo de destino não há como deduzir o texto a partir da data, então
     * informar só o período deixaria o registro invisível no documento.
     */
    #[Test]
    public function exige_descricao_quando_o_escalado_informa_periodo(): void
    {
        $escala = $this->escalaMontada();

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->set('periodoInicio', '2026-08-12')
            ->set('observacao', '')
            ->call('salvarDetalhes')
            ->assertHasErrors('observacao');

        $this->assertNull($lotacao->fresh()->observacao);
    }

    /**
     * Motorista com 8 plantões previstos que faltou a um deve aparecer com 7 na
     * coluna PLANTÕES do documento.
     */
    #[Test]
    public function ajusta_a_quantidade_de_plantoes_ao_registrar_a_falta(): void
    {
        $escala = $this->escalaMontada();
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        $calculado = (int) $lotacao->plantoes_previstos;
        $this->assertGreaterThan(0, $calculado);

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->set('observacao', 'Falta em 12/08')
            ->set('plantoesPrevistos', $calculado - 1)
            ->call('salvarDetalhes')
            ->assertHasNoErrors();

        $lotacao->refresh();

        // A contagem da escala continua intacta; o ajuste fica à parte.
        $this->assertSame($calculado, (int) $lotacao->plantoes_previstos);
        $this->assertSame($calculado - 1, $lotacao->plantoes_ajustados);
        $this->assertSame($calculado - 1, $lotacao->plantoesEfetivos());
        $this->assertSame(-1, $lotacao->diferencaDePlantoes());
        $this->assertTrue($lotacao->plantoesForamAjustados());

        // E é o número ajustado que sai no documento.
        $linha = app(GeradorDeDocumentos::class)
            ->linhasDeOcorrencias($escala->fresh())
            ->firstWhere('nome', $lotacao->motorista->nomeDocumento());

        $this->assertSame($calculado - 1, $linha['plantoes']);
        $this->assertSame('Falta em 12/08', $linha['ocorrencia']);
    }

    /**
     * O ajuste precisa sobreviver a uma nova geração de plantões — do contrário
     * seria silenciosamente desfeito, que é o motivo de não gravá-lo direto em
     * plantoes_previstos.
     */
    #[Test]
    public function o_ajuste_de_plantoes_sobrevive_a_regeracao(): void
    {
        $escala = $this->escalaMontada();
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        $calculado = (int) $lotacao->plantoes_previstos;
        $lotacao->update(['observacao' => 'Falta em 12/08', 'plantoes_ajustados' => $calculado - 1]);

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $lotacao->refresh();

        $this->assertSame($calculado, (int) $lotacao->plantoes_previstos);
        $this->assertSame($calculado - 1, $lotacao->plantoesEfetivos());
    }

    /** Informar o mesmo número calculado não grava ajuste algum. */
    #[Test]
    public function informar_o_valor_calculado_nao_registra_ajuste(): void
    {
        $escala = $this->escalaMontada();
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->set('observacao', 'Troca de plantão com Fulano')
            ->set('plantoesPrevistos', (int) $lotacao->plantoes_previstos)
            ->call('salvarDetalhes');

        $this->assertNull($lotacao->fresh()->plantoes_ajustados);
        $this->assertFalse($lotacao->fresh()->plantoesForamAjustados());
    }

    /** Remover a ocorrência devolve a contagem da escala. */
    #[Test]
    public function remover_a_ocorrencia_desfaz_o_ajuste_de_plantoes(): void
    {
        $escala = $this->escalaMontada();
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        $calculado = (int) $lotacao->plantoes_previstos;
        $lotacao->update(['observacao' => 'Falta em 12/08', 'plantoes_ajustados' => $calculado - 1]);

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->call('limparOcorrencia');

        $lotacao->refresh();

        $this->assertNull($lotacao->plantoes_ajustados);
        $this->assertSame($calculado, $lotacao->plantoesEfetivos());
    }

    /** Para quem tem destino administrativo o número continua sendo direto. */
    #[Test]
    public function afastado_continua_com_plantoes_informados_direto(): void
    {
        $escala = $this->escalaMontada();
        $apoio = Motorista::factory()->create();

        app(MontadorDeEscala::class)->definirDestino($escala, $apoio->id, TipoDestino::Apoio);

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $apoio->id)
            ->firstOrFail();

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->set('plantoesPrevistos', 6)
            ->call('salvarDetalhes');

        $lotacao->refresh();

        // Sem escala para contar, o valor vai direto em plantoes_previstos.
        $this->assertSame(6, (int) $lotacao->plantoes_previstos);
        $this->assertNull($lotacao->plantoes_ajustados);
        $this->assertSame(6, $lotacao->plantoesEfetivos());
    }

    /** A ocorrência pode ser removida depois. */
    #[Test]
    public function remove_a_ocorrencia_do_escalado(): void
    {
        $escala = $this->escalaMontada();

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        $lotacao->update(['observacao' => 'Falta em 12/08', 'periodo_inicio' => '2026-08-12']);

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->call('limparOcorrencia');

        $lotacao->refresh();

        $this->assertNull($lotacao->observacao);
        $this->assertNull($lotacao->periodo_inicio);
        // Segue escalado.
        $this->assertTrue($lotacao->escalado());
    }

    /** Regerar os plantões não apaga a ocorrência registrada. */
    #[Test]
    public function a_ocorrencia_sobrevive_a_regeracao_dos_plantoes(): void
    {
        $escala = $this->escalaMontada();
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        $lotacao->update(['observacao' => 'Atestado de 10 a 12/08']);

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $this->assertSame('Atestado de 10 a 12/08', $lotacao->fresh()->observacao);
    }

    /** O filtro reúne quem tem ocorrência, escalado ou não. */
    #[Test]
    public function filtra_por_quem_tem_ocorrencia(): void
    {
        $escala = $this->escalaMontada();

        $escalado = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->firstOrFail();

        $escalado->update(['observacao' => 'Falta em 12/08']);

        $afastado = Motorista::factory()->create();
        app(MontadorDeEscala::class)->definirDestino(
            $escala,
            $afastado->id,
            TipoDestino::Licenca,
            observacao: 'Licença para tratamento de saúde'
        );

        $componente = Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('filtrar', 'com_ocorrencia');

        $this->assertSame(2, $componente->get('contagens')['com_ocorrencia']);
        $componente->assertSee('Falta em 12/08')
            ->assertSee('Licença para tratamento de saúde');
    }

    #[Test]
    public function recusa_periodo_com_fim_antes_do_inicio(): void
    {
        $escala = $this->escalaMontada();
        $motorista = Motorista::factory()->create();
        app(MontadorDeEscala::class)->definirDestino($escala, $motorista->id, TipoDestino::Licenca);

        $lotacao = EscalaLotacao::query()->where('motorista_id', $motorista->id)->firstOrFail();

        Livewire::test(DefinirDestinos::class, ['escala' => $escala->fresh()])
            ->call('editar', $lotacao->id)
            ->set('periodoInicio', '2026-08-20')
            ->set('periodoFim', '2026-08-05')
            ->call('salvarDetalhes')
            ->assertHasErrors('periodoFim');
    }

    // -----------------------------------------------------------------
    // Geração e publicação
    // -----------------------------------------------------------------

    #[Test]
    public function gera_os_plantoes_pela_rota(): void
    {
        $escala = $this->escalaMontada();

        $this->post(route('escalas.gerar', $escala))
            ->assertRedirect(route('escalas.show', $escala))
            ->assertSessionHas('sucesso');

        $this->assertSame(31, $escala->fresh()->plantoes()->count());
    }

    #[Test]
    public function publica_a_escala_completa(): void
    {
        $escala = $this->escalaMontada();
        app(MontadorDeEscala::class)->definirRestantesComoReserva($escala->fresh()->load('postos', 'lotacoes'));
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $this->post(route('escalas.publicar', $escala))
            ->assertRedirect(route('documentos.index', $escala))
            ->assertSessionHas('sucesso');

        $escala->refresh();

        $this->assertSame(StatusEscala::Publicada, $escala->status);
        $this->assertNotNull($escala->publicada_em);
    }

    /** Escala com pendencia nao e publicada. */
    #[Test]
    public function nao_publica_escala_com_vaga_aberta(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();

        // Apenas 2 dos 4 exigidos.
        foreach (Motorista::factory()->count(2)->create()->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $this->post(route('escalas.publicar', $escala))->assertSessionHas('erro');

        $this->assertSame(StatusEscala::Rascunho, $escala->fresh()->status);
    }

    #[Test]
    public function reabre_e_arquiva_a_escala(): void
    {
        $escala = $this->escalaMontada();
        $escala->update(['status' => StatusEscala::Publicada, 'publicada_em' => now()]);

        $this->post(route('escalas.reabrir', $escala))->assertSessionHas('atencao');
        $this->assertSame(StatusEscala::Rascunho, $escala->fresh()->status);

        $this->post(route('escalas.arquivar', $escala))->assertSessionHas('sucesso');
        $this->assertSame(StatusEscala::Arquivada, $escala->fresh()->status);
    }

    /** Escala arquivada nao aceita alteracao. */
    #[Test]
    public function escala_arquivada_nao_abre_a_montagem(): void
    {
        $escala = $this->escalaMontada();
        $escala->update(['status' => StatusEscala::Arquivada]);

        $this->get(route('escalas.montar', $escala))->assertForbidden();
        $this->post(route('escalas.gerar', $escala))->assertForbidden();
    }

    /** Escala publicada precisa ser reaberta antes de excluir. */
    #[Test]
    public function nao_exclui_escala_publicada_sem_reabrir(): void
    {
        $escala = $this->escalaMontada();
        $escala->update(['status' => StatusEscala::Publicada]);

        $this->delete(route('escalas.destroy', $escala))->assertSessionHas('erro');
        $this->assertNotNull($escala->fresh());
    }

    #[Test]
    public function exclui_escala_em_rascunho(): void
    {
        $escala = $this->escalaMontada();

        $this->delete(route('escalas.destroy', $escala))
            ->assertRedirect(route('escalas.index'))
            ->assertSessionHas('sucesso');

        $this->assertNull(Escala::query()->find($escala->id));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Escala de agosto/2026 com uma ambulancia 24/72 e nenhum motorista lotado. */
    /**
     * Escolher "vaga aberta" no seletor precisa liberar a posicao.
     *
     * O <select> manda texto, sempre: a opcao vazia chega como string vazia.
     * Com o parametro declarado ?int, o PHP recusava a chamada antes do corpo
     * rodar -- e o Livewire converte TypeError em abort(419) quando o
     * APP_DEBUG esta desligado. Em producao o coordenador via "This page has
     * expired" e concluia que a sessao tinha caido.
     */
    #[Test]
    public function liberar_a_posicao_pelo_seletor_funciona_com_valor_vazio(): void
    {
        $escala = $this->escalaMontada();
        $posto = $escala->postos()->first();

        $this->assertNotNull(
            EscalaLotacao::query()->where('escala_posto_id', $posto->id)->where('posicao', 1)->first()
        );

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('lotar', $posto->id, 1, '');

        $this->assertNull(
            EscalaLotacao::query()->where('escala_posto_id', $posto->id)->where('posicao', 1)->first(),
            'A posicao deveria ter ficado aberta.'
        );
    }

    /** O seletor tambem manda o id como texto ao lotar alguem. */
    #[Test]
    public function lotar_pelo_seletor_funciona_com_id_em_texto(): void
    {
        $escala = $this->escalaMontada();
        $posto = $escala->postos()->first();
        $novo = Motorista::factory()->create();

        Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->call('lotar', $posto->id, 1, (string) $novo->id);

        $this->assertSame(
            $novo->id,
            EscalaLotacao::query()->where('escala_posto_id', $posto->id)->where('posicao', 1)->first()?->motorista_id
        );
    }

    /**
     * Remanejar alguem de uma ambulancia para outra, sem desmontar o mes.
     *
     * E o caso rotineiro: uma motorista entra de ferias, quem estava em outro
     * posto assume a vaga dela e o apoio cobre a posicao que abriu. O seletor
     * de cada posto precisa oferecer quem ja esta escalado alhures -- sem isso
     * o coordenador so consegue mexer esvaziando a origem antes, e parece que
     * a escala inteira teria de ser refeita.
     */
    #[Test]
    public function remaneja_motorista_de_uma_ambulancia_para_outra(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->count(2)->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        [$sede1, $sede2] = $escala->postos()->orderBy('id')->get()->all();

        $motoristas = Motorista::factory()->count(8)->create()->values();
        foreach ($motoristas as $i => $motorista) {
            $posto = $i < 4 ? $sede1 : $sede2;
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, ($i % 4) + 1);
        }

        $edson = $motoristas[0];   // SEDE 1, posicao 1
        $divanir = $motoristas[6]; // SEDE 2, posicao 3

        $componente = Livewire::test(MontarEscala::class, ['escala' => $escala->fresh()]);

        // A tela precisa oferecer quem esta em outro posto; era isso que faltava.
        $oferecidos = $componente->instance()->escaladosEmOutrosPostos()->pluck('motorista_id');
        $this->assertTrue($oferecidos->contains($edson->id));

        // Traz EDSON para a vaga de DIVANIR na outra ambulancia.
        $componente->call('lotar', $sede2->id, 3, $edson->id);

        $lotacaoEdson = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $edson->id)
            ->firstOrFail();

        $this->assertSame($sede2->id, $lotacaoEdson->escala_posto_id);
        $this->assertSame(3, $lotacaoEdson->posicao);

        // Ele nao fica em dois lugares: a posicao de origem abriu.
        $this->assertNull(
            EscalaLotacao::query()->where('escala_posto_id', $sede1->id)->where('posicao', 1)->first()
        );

        // E DIVANIR ficou sem definicao, pronta para ir para ferias.
        $lotacaoDivanir = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $divanir->id)
            ->firstOrFail();

        $this->assertNull($lotacaoDivanir->escala_posto_id);
        $this->assertNull($lotacaoDivanir->posicao);
    }

    private function escalaVazia(): Escala
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        return app(MontadorDeEscala::class)->criar(2026, 8);
    }

    /** A mesma escala, com as quatro vagas preenchidas. */
    private function escalaMontada(): Escala
    {
        $escala = $this->escalaVazia();
        $posto = $escala->postos()->first();

        foreach (Motorista::factory()->count(4)->create()->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        return $escala->fresh();
    }
}
