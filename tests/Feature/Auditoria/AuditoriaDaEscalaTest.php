<?php

namespace Tests\Feature\Auditoria;

use App\Enums\AcaoAuditada;
use App\Enums\CategoriaDeAuditoria;
use App\Enums\StatusEscala;
use App\Enums\TipoDestino;
use App\Livewire\DefinirDestinos;
use App\Livewire\MontarEscala;
use App\Livewire\OrdenarPostos;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use App\Models\RegistroDeAuditoria;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Criacao, montagem, geracao, publicacao e destinos da escala. Toda linha aqui diz o
 * mes e aponta para a escala, para o filtro por escala funcionar.
 */
class AuditoriaDaEscalaTest extends TestCase
{
    use RefreshDatabase;

    private MontadorDeEscala $montador;

    private Unidade $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['nome' => 'Carlos Lima']));
        $this->montador = app(MontadorDeEscala::class);

        $this->unidade = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        Ambulancia::factory()->create(['unidade_id' => $this->unidade->id, 'identificacao' => 'SEDE 1', 'placa' => 'THQ4H34']);
    }

    // -----------------------------------------------------------------
    // Ciclo da escala
    // -----------------------------------------------------------------

    #[Test]
    public function criar_a_escala_entra_na_auditoria(): void
    {
        $this->post(route('escalas.store'), ['ano' => 2026, 'mes' => 8, 'copiar_mes_anterior' => 0]);

        $registro = $this->registro(AcaoAuditada::EscalaCriada);

        $this->assertSame('Criou a escala a partir da frota — Agosto de 2026', $registro->descricao);
        $this->assertSame(CategoriaDeAuditoria::Escala, $registro->categoria);
        $this->assertSame(Escala::query()->sole()->id, $registro->escala_id);
        $this->assertSame('Carlos Lima', $registro->usuario_nome);
    }

    /** Pedir a cópia sem existir mês anterior monta da frota: a auditoria diz o que aconteceu. */
    #[Test]
    public function criar_copiando_o_mes_anterior_so_diz_isso_quando_houve_copia(): void
    {
        $this->post(route('escalas.store'), ['ano' => 2026, 'mes' => 8, 'copiar_mes_anterior' => 1]);
        $this->assertStringStartsWith('Criou a escala a partir da frota', $this->registro(AcaoAuditada::EscalaCriada)->descricao);

        $this->post(route('escalas.store'), ['ano' => 2026, 'mes' => 9, 'copiar_mes_anterior' => 1]);
        $this->assertStringStartsWith('Criou a escala, copiando a estrutura do mês anterior', $this->registro(AcaoAuditada::EscalaCriada)->descricao);
    }

    #[Test]
    public function duplicar_e_renomear_a_proposta_entram_na_auditoria(): void
    {
        $escala = $this->escalaMontada();

        $this->post(route('escalas.duplicar', $escala), ['nome' => 'Com contratações']);
        $nova = Escala::query()->latest('id')->first();

        $this->assertSame('Criou uma nova proposta a partir de a escala original — Agosto de 2026 · Com contratações', $this->registro(AcaoAuditada::EscalaDuplicada)->descricao);
        $this->assertSame($nova->id, $this->registro(AcaoAuditada::EscalaDuplicada)->escala_id);

        $this->put(route('escalas.renomear', $nova), ['nome' => 'Sem a SEDE 4']);

        $this->assertEquals(
            ['campo' => 'Nome', 'de' => 'Com contratações', 'para' => 'Sem a SEDE 4'],
            $this->campo($this->registro(AcaoAuditada::EscalaRenomeada), 'Nome'),
        );
    }

    #[Test]
    public function gerar_os_plantoes_entra_na_auditoria_pelos_dois_botoes(): void
    {
        $escala = $this->escalaMontada(gerar: false);

        $this->post(route('escalas.gerar', $escala), ['descartar_ajustes' => 1]);

        $this->assertSame(
            'Gerou os plantões, descartando as trocas manuais (31 plantões gerados) — Agosto de 2026',
            $this->registro(AcaoAuditada::EscalaGerada)->descricao,
        );

        Livewire::test(MontarEscala::class, ['escala' => $escala])->call('gerarPlantoes');

        $this->assertSame(2, RegistroDeAuditoria::query()->where('acao', AcaoAuditada::EscalaGerada->value)->count());
        $this->assertSame('Gerou os plantões (31 plantões gerados) — Agosto de 2026', $this->registro(AcaoAuditada::EscalaGerada)->descricao);
    }

    /** Gerar sem nenhum posto recusa e não muda nada: não é uma ação a registrar. */
    #[Test]
    public function gerar_sem_conseguir_nao_entra_na_auditoria(): void
    {
        $escala = Escala::query()->create(['ano' => 2026, 'mes' => 9, 'status' => StatusEscala::Rascunho]);

        $this->post(route('escalas.gerar', $escala))->assertSessionHas('erro');

        $this->assertSame(0, RegistroDeAuditoria::query()->count());
    }

    #[Test]
    public function publicar_registra_quem_aprovou_e_o_que_foi_feito_das_outras_propostas(): void
    {
        $escala = $this->escalaMontada();
        $this->montador->duplicar($escala, 'Alternativa');

        $this->post(route('escalas.publicar', $escala), [
            'outras' => 'excluir',
            'aprovada_por' => 'Secretária de Saúde',
            'aprovada_em' => '2026-07-25',
        ])->assertRedirect();

        $registro = $this->registro(AcaoAuditada::EscalaPublicada);

        $this->assertSame(StatusEscala::Publicada, $escala->fresh()->status);
        $this->assertStringStartsWith('Publicou a escala — ', $registro->descricao);
        $this->assertSame('Secretária de Saúde', $this->campo($registro, 'Aprovada por')['para']);
        $this->assertSame('25/07/2026', $this->campo($registro, 'Aprovada em')['para']);
        $this->assertSame('Alternativa', $this->campo($registro, 'Outras propostas excluídas')['para']);
    }

    #[Test]
    public function guardar_as_outras_propostas_como_descartadas_fica_registrado(): void
    {
        $escala = $this->escalaMontada();
        $this->montador->duplicar($escala, 'Alternativa');

        $this->post(route('escalas.publicar', $escala), ['outras' => 'descartar']);

        $this->assertSame('Alternativa', $this->campo($this->registro(AcaoAuditada::EscalaPublicada), 'Outras propostas guardadas como descartadas')['para']);
    }

    /** Pedido explícito da coordenação: a publicação com pendências fica na auditoria. */
    #[Test]
    public function publicar_com_pendencias_registra_o_que_foi_aceito_e_o_motivo(): void
    {
        $escala = $this->escalaMontada();
        $dia1 = $escala->plantoes()->whereDate('data', '2026-08-01')->first();
        $escala->plantoes()->whereDate('data', '2026-08-02')->first()
            ->update(['motorista_id' => $dia1->motorista_id, 'ajuste_manual' => true]);

        $this->post(route('escalas.publicar', $escala), [
            'aceitar_pendencias' => 1,
            'justificativa' => 'Descanso curto combinado com o motorista.',
        ]);

        $registro = $this->registro(AcaoAuditada::EscalaPublicada);

        $this->assertStringContainsString('pendência(s) aceita(s)', $registro->descricao);
        $this->assertSame('Descanso curto combinado com o motorista.', $this->campo($registro, 'Motivo')['para']);
        $this->assertSame($escala->fresh()->pendencias_aceitas[0]['mensagem'], explode("\n", $this->campo($registro, 'Pendências aceitas')['para'])[0]);
    }

    #[Test]
    public function reabrir_arquivar_restaurar_e_excluir_entram_na_auditoria(): void
    {
        $escala = $this->escalaMontada();
        $proposta = $this->montador->duplicar($escala, 'Alternativa');
        $this->montador->publicar($escala->fresh(), excluirOutras: false);

        $this->post(route('escalas.reabrir', $escala));
        $this->assertEquals(['campo' => 'Situação', 'de' => 'Publicada', 'para' => 'Rascunho'], $this->campo($this->registro(AcaoAuditada::EscalaReaberta), 'Situação'));

        $this->post(route('escalas.restaurar', $proposta->fresh()));
        $this->assertSame('Rascunho', $this->campo($this->registro(AcaoAuditada::EscalaRestaurada), 'Situação')['para']);

        $this->delete(route('escalas.destroy', $proposta->fresh()));
        $this->assertStringStartsWith('Excluiu a escala — Agosto de 2026', $this->registro(AcaoAuditada::EscalaExcluida)->descricao);
        $this->assertModelMissing($proposta);

        $this->montador->publicar($escala->fresh());
        $this->post(route('escalas.arquivar', $escala));
        $this->assertSame('Arquivada', $this->campo($this->registro(AcaoAuditada::EscalaArquivada), 'Situação')['para']);
    }

    // -----------------------------------------------------------------
    // Montagem
    // -----------------------------------------------------------------

    /** O exemplo do plano: quem, em que posição, de qual ambulância, em que mês. */
    #[Test]
    public function lotar_diz_quem_onde_de_onde_veio_e_quem_saiu(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $posto = $escala->postos()->first();
        $lotados = $this->lotados($posto);
        $novo = Motorista::factory()->create(['nome_curto' => 'NOVATO']);

        $tela = Livewire::test(MontarEscala::class, ['escala' => $escala]);

        $tela->call('lotar', $posto->id, 2, $novo->id);

        $registro = $this->registro(AcaoAuditada::MotoristaLotado);

        $this->assertSame('Lotou NOVATO na posição 2 de SEDE 1 — Agosto de 2026', $registro->descricao);
        $this->assertSame($lotados[2], $this->campo($registro, 'Saiu da vaga')['para']);
        $this->assertSame($novo->id, $registro->alvo_id);

        // Movido de outra ambulância: diz de onde veio.
        $outraAmbulancia = Ambulancia::factory()->create(['unidade_id' => $this->unidade->id, 'identificacao' => 'SEDE 2']);
        $outroPosto = $this->montador->adicionarPosto($escala, $this->unidade->id, $outraAmbulancia->id);

        $tela->call('lotar', $outroPosto->id, 1, $novo->id);

        $this->assertSame('SEDE 1, posição 2', $this->campo($this->registro(AcaoAuditada::MotoristaLotado), 'Antes estava em')['para']);

        // Escolher de novo quem já está lá não é uma ação.
        $total = RegistroDeAuditoria::query()->count();
        $tela->call('lotar', $outroPosto->id, 1, $novo->id);
        $this->assertSame($total, RegistroDeAuditoria::query()->count());
    }

    #[Test]
    public function retirar_e_trocar_de_posicao_entram_na_auditoria(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $posto = $escala->postos()->first();
        $lotados = $this->lotados($posto);

        $tela = Livewire::test(MontarEscala::class, ['escala' => $escala]);

        $tela->call('mover', $posto->id, 1, 1);

        $trocou = $this->registro(AcaoAuditada::PosicaoTrocada);
        $this->assertSame("Passou {$lotados[1]} da posição 1 para a 2 em SEDE 1 — Agosto de 2026", $trocou->descricao);
        $this->assertSame($lotados[2], $this->campo($trocou, 'Trocou de lugar com')['para']);

        $tela->call('lotar', $posto->id, 3, null);

        $this->assertSame("Retirou {$lotados[3]} da posição 3 de SEDE 1 — Agosto de 2026", $this->registro(AcaoAuditada::PosicaoLiberada)->descricao);

        // Posição já vazia: nada a registrar.
        $total = RegistroDeAuditoria::query()->count();
        $tela->call('liberarPosicao', $posto->id, 3);
        $this->assertSame($total, RegistroDeAuditoria::query()->count());
    }

    #[Test]
    public function adicionar_e_remover_ambulancia_entram_na_auditoria(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $outra = Ambulancia::factory()->create(['unidade_id' => $this->unidade->id, 'identificacao' => 'SEDE 2', 'placa' => 'ABC1D23']);

        $tela = Livewire::test(MontarEscala::class, ['escala' => $escala])
            ->set('novaUnidadeId', $this->unidade->id)
            ->set('novaAmbulanciaId', $outra->id)
            ->call('adicionarPosto');

        $this->assertStringContainsString('Adicionou a ambulância SEDE 2 · ABC1D23', $this->registro(AcaoAuditada::PostoAdicionado)->descricao);

        $posto = $escala->postos()->first();
        $lotados = $this->lotados($posto);

        $tela->call('removerPosto', $posto->id);

        $registro = $this->registro(AcaoAuditada::PostoRemovido);

        $this->assertStringContainsString('Removeu a ambulância SEDE 1 · THQ4H34', $registro->descricao);
        // A equipe sai junto, em cascata: só resta aqui.
        $this->assertStringContainsString($lotados[1], $this->campo($registro, 'Equipe que estava nela')['para']);
    }

    #[Test]
    public function remanejar_periodo_e_rotacao_da_ambulancia_entram_na_auditoria(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $posto = $escala->postos()->first();
        $outra = Unidade::factory()->create(['sigla' => 'UPA', 'horas_trabalho' => 24, 'horas_descanso' => 48]);

        $tela = Livewire::test(MontarEscala::class, ['escala' => $escala]);

        $tela->call('alterarUnidadeDoPosto', $posto->id, (string) $outra->id);

        $remanejou = $this->registro(AcaoAuditada::PostoUnidadeAlterada);
        $this->assertStringContainsString('para a unidade UPA, liberando 1 motorista(s)', $remanejou->descricao);
        $this->assertEquals(['campo' => 'Unidade', 'de' => 'SEDE', 'para' => 'UPA'], $this->campo($remanejou, 'Unidade'));

        $tela->call('alterarVigencia', $posto->id, 'data_inicio', '2026-08-10');
        $this->assertSame('10/08/2026', $this->campo($this->registro(AcaoAuditada::PostoVigenciaAlterada), 'Início da operação')['para']);

        // Data fora do mês é recusada: nada mudou.
        $total = RegistroDeAuditoria::query()->count();
        $tela->call('alterarVigencia', $posto->id, 'data_inicio', '2026-09-10');
        $this->assertSame($total, RegistroDeAuditoria::query()->count());

        $tela->call('alternarRotacao', $posto->id);
        $this->assertStringContainsString('passou a reiniciar na posição 1', $this->registro(AcaoAuditada::PostoRotacaoAlterada)->descricao);
    }

    #[Test]
    public function preencher_as_vagas_e_uma_linha_so_e_nao_uma_por_motorista(): void
    {
        $escala = $this->montador->criar(2026, 8, copiarMesAnterior: false);
        Motorista::factory()->count(4)->create();

        Livewire::test(MontarEscala::class, ['escala' => $escala])->call('preencherAutomaticamente');

        $this->assertStringContainsString('Preencheu 4 vaga(s) automaticamente', $this->registro(AcaoAuditada::VagasPreenchidas)->descricao);
        $this->assertNull(RegistroDeAuditoria::query()->where('acao', AcaoAuditada::MotoristaLotado->value)->first());
    }

    #[Test]
    public function preencher_sem_nenhuma_vaga_preenchida_nao_entra_na_auditoria(): void
    {
        $escala = $this->escalaMontada(gerar: false);

        Livewire::test(MontarEscala::class, ['escala' => $escala])->call('preencherAutomaticamente');

        $this->assertNull(RegistroDeAuditoria::query()->where('acao', AcaoAuditada::VagasPreenchidas->value)->first());
    }

    #[Test]
    public function mudar_a_ordem_das_ambulancias_entra_na_auditoria(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $outra = Ambulancia::factory()->create(['unidade_id' => $this->unidade->id, 'identificacao' => 'SEDE 2']);
        $segundo = $this->montador->adicionarPosto($escala, $this->unidade->id, $outra->id);

        $tela = Livewire::test(OrdenarPostos::class, ['escala' => $escala]);

        $tela->call('mover', $segundo->id, -1);

        $this->assertSame('Moveu SEDE 2 para cima na ordem das ambulâncias — Agosto de 2026', $this->registro(AcaoAuditada::PostoReordenado)->descricao);

        // Já é o primeiro: não sobe mais, e por isso não há o que registrar.
        $tela->call('mover', $segundo->id, -1);
        $this->assertSame(1, RegistroDeAuditoria::query()->where('acao', AcaoAuditada::PostoReordenado->value)->count());
    }

    // -----------------------------------------------------------------
    // Destinos
    // -----------------------------------------------------------------

    #[Test]
    public function definir_e_limpar_o_destino_entram_na_auditoria(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $motorista = Motorista::factory()->create(['nome_curto' => 'JOÃO']);
        EscalaLotacao::query()->create(['escala_id' => $escala->id, 'motorista_id' => $motorista->id]);

        $tela = Livewire::test(DefinirDestinos::class, ['escala' => $escala]);

        $tela->call('definir', $motorista->id, TipoDestino::Ferias->value);

        $definiu = $this->registro(AcaoAuditada::DestinoDefinido);
        $this->assertSame('Definiu o destino de JOÃO: '.TipoDestino::Ferias->rotulo().' — Agosto de 2026', $definiu->descricao);
        $this->assertEquals(['campo' => 'Destino', 'de' => null, 'para' => TipoDestino::Ferias->rotulo()], $this->campo($definiu, 'Destino'));

        $tela->call('definir', $motorista->id, null);

        $this->assertSame(TipoDestino::Ferias->rotulo(), $this->campo($this->registro(AcaoAuditada::DestinoLimpo), 'Destino')['de']);
    }

    /** Tirar alguém de uma ambulância para férias: o destino anterior é a ambulância. */
    #[Test]
    public function mandar_um_escalado_para_outro_destino_diz_de_qual_ambulancia_saiu(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $lotacao = EscalaLotacao::query()->where('escala_id', $escala->id)->whereNotNull('escala_posto_id')->first();

        Livewire::test(DefinirDestinos::class, ['escala' => $escala])
            ->call('definir', $lotacao->motorista_id, TipoDestino::Licenca->value);

        $this->assertSame('SEDE 1', $this->campo($this->registro(AcaoAuditada::DestinoDefinido), 'Destino')['de']);
    }

    #[Test]
    public function alterar_e_remover_a_ocorrencia_entram_na_auditoria(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $lotacao = EscalaLotacao::query()->where('escala_id', $escala->id)->whereNotNull('escala_posto_id')->with('motorista')->first();

        $tela = Livewire::test(DefinirDestinos::class, ['escala' => $escala])
            ->call('editar', $lotacao->id)
            ->set('periodoInicio', '2026-08-10')
            ->set('periodoFim', '2026-08-12')
            ->set('observacao', 'Atestado médico')
            ->call('salvarDetalhes');

        $alterou = $this->registro(AcaoAuditada::DetalhesDaLotacaoAlterados);
        $this->assertStringContainsString($lotacao->motorista->nome_curto, $alterou->descricao);
        $this->assertSame('Atestado médico', $this->campo($alterou, 'Observação')['para']);
        $this->assertSame('10/08/2026', $this->campo($alterou, 'Início do período')['para']);

        $tela->call('editar', $lotacao->id)->call('limparOcorrencia');

        $this->assertSame('Atestado médico', $this->campo($this->registro(AcaoAuditada::OcorrenciaRemovida), 'Observação')['de']);
    }

    #[Test]
    public function mandar_os_restantes_para_reserva_diz_quais_foram(): void
    {
        $escala = $this->escalaMontada(gerar: false);
        $restante = Motorista::factory()->create(['nome_curto' => 'SOBROU']);
        EscalaLotacao::query()->create(['escala_id' => $escala->id, 'motorista_id' => $restante->id]);

        Livewire::test(DefinirDestinos::class, ['escala' => $escala])->call('todosParaReserva');

        $registro = $this->registro(AcaoAuditada::RestantesParaReserva);

        $this->assertStringStartsWith('Definiu 1 motorista(s) sem destino como reserva/sobreaviso', $registro->descricao);
        $this->assertSame('SOBROU', $this->campo($registro, 'Motoristas')['para']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function registro(AcaoAuditada $acao): RegistroDeAuditoria
    {
        return RegistroDeAuditoria::query()->where('acao', $acao->value)->latest('id')->firstOrFail();
    }

    /** @return array{campo: string, de: ?string, para: ?string}|null */
    private function campo(RegistroDeAuditoria $registro, string $rotulo): ?array
    {
        return collect($registro->alteracoes ?? [])->firstWhere('campo', $rotulo);
    }

    /** @return Collection<int, string> Nome de quem está em cada posição. */
    private function lotados(EscalaPosto $posto): Collection
    {
        return EscalaLotacao::query()
            ->where('escala_posto_id', $posto->id)
            ->with('motorista')
            ->get()
            ->mapWithKeys(fn (EscalaLotacao $l) => [$l->posicao => $l->motorista->nome_curto]);
    }

    private function escalaMontada(bool $gerar = true): Escala
    {
        $escala = $this->montador->criar(2026, 8, copiarMesAnterior: false);
        $posto = $escala->postos()->first();

        foreach (Motorista::factory()->count(4)->create()->values() as $i => $motorista) {
            $this->montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        if ($gerar) {
            app(GeradorDeEscala::class)->gerar($escala->fresh());
        }

        return $escala->fresh();
    }
}
