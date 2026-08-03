<?php

namespace Tests\Feature\Escalas;

use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MontadorDeEscalaTest extends TestCase
{
    use RefreshDatabase;

    /** Sem mes anterior, os postos saem da frota ativa com o regime da unidade. */
    #[Test]
    public function monta_os_postos_a_partir_da_frota_ativa(): void
    {
        $upa = Unidade::factory()->regime2472()->create(['sigla' => 'UPA', 'ordem' => 10]);
        $praia = Unidade::factory()->regime2448()->create(['sigla' => 'PRAIA', 'ordem' => 20]);

        Ambulancia::factory()->count(2)->create(['unidade_id' => $upa->id]);
        Ambulancia::factory()->create(['unidade_id' => $praia->id]);
        Ambulancia::factory()->inativa()->create(['unidade_id' => $upa->id]);
        Ambulancia::factory()->create(['unidade_id' => null]); // sem lotacao, fica de fora

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $escala->load('postos');

        $this->assertCount(3, $escala->postos);
        $this->assertSame(11, $escala->postos->sum(fn ($p) => $p->vagas())); // 4 + 4 + 3

        // A UPA vem antes da praia por causa da ordem definida no cadastro.
        $this->assertSame('UPA', $escala->postos->first()->rotuloLotacao());
    }

    /** O caminho do dia a dia: repetir a estrutura do mes anterior. */
    #[Test]
    public function copia_a_estrutura_do_mes_anterior(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $agosto = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $agosto->postos()->first();

        $motoristas = Motorista::factory()->count(4)->create();

        foreach ($motoristas->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        $setembro = app(MontadorDeEscala::class)->criar(2026, 9);
        $setembro->load('postos.lotacoes');

        $this->assertCount(1, $setembro->postos);
        $this->assertCount(4, $setembro->postos->first()->lotacoes);
        $this->assertSame(
            $motoristas->pluck('id')->all(),
            $setembro->postos->first()->lotacoes->pluck('motorista_id')->all()
        );
        // O regime e a ambulancia acompanham a copia.
        $this->assertSame('24/72', $setembro->postos->first()->regimeNotacao());
        $this->assertSame($posto->ambulancia_id, $setembro->postos->first()->ambulancia_id);
    }

    /**
     * Motorista que ficou impedido no novo mes nao e copiado: a vaga fica aberta
     * para o coordenador resolver, em vez de gerar escala invalida em silencio.
     */
    #[Test]
    public function nao_copia_motorista_impedido_no_novo_mes(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $agosto = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $agosto->postos()->first();

        $regulares = Motorista::factory()->count(3)->create();
        // Contrato encerra antes de setembro.
        $contratoVencido = Motorista::factory()->contratado('2026-08-31')->create();

        foreach ($regulares->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }
        app(MontadorDeEscala::class)->lotarMotorista($posto, $contratoVencido->id, 4);

        $setembro = app(MontadorDeEscala::class)->criar(2026, 9);
        $setembro->load('postos.lotacoes');

        $lotados = $setembro->postos->first()->lotacoes->pluck('motorista_id');

        $this->assertCount(3, $lotados);
        $this->assertFalse($lotados->contains($contratoVencido->id));
        $this->assertSame(1, $setembro->postos->first()->vagasLivres());
    }

    /**
     * Reserva e apoio seguem para o mes seguinte; ferias e licenca ficam no mes
     * de origem, pois valem para aquele periodo.
     */
    #[Test]
    public function copia_reserva_mas_nao_copia_afastamentos(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $agosto = app(MontadorDeEscala::class)->criar(2026, 8);

        $reserva = Motorista::factory()->create();
        $ferias = Motorista::factory()->create();
        $licenca = Motorista::factory()->create();

        $montador = app(MontadorDeEscala::class);
        $montador->definirDestino($agosto, $reserva->id, TipoDestino::Reserva);
        $montador->definirDestino($agosto, $ferias->id, TipoDestino::Ferias);
        $montador->definirDestino($agosto, $licenca->id, TipoDestino::Licenca);

        $setembro = $montador->criar(2026, 9);

        $destinos = EscalaLotacao::query()
            ->where('escala_id', $setembro->id)
            ->pluck('tipo_destino', 'motorista_id');

        $this->assertSame(TipoDestino::Reserva, $destinos[$reserva->id]);
        $this->assertArrayNotHasKey($ferias->id, $destinos->all());
        $this->assertArrayNotHasKey($licenca->id, $destinos->all());
    }

    /** Um motorista tem um unico destino por mes: lotar move a lotacao. */
    #[Test]
    public function lotar_em_um_posto_remove_o_destino_anterior(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();
        $motorista = Motorista::factory()->create();

        $montador = app(MontadorDeEscala::class);
        $montador->definirDestino($escala, $motorista->id, TipoDestino::Reserva);
        $montador->lotarMotorista($posto, $motorista->id, 1);

        $lotacoes = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $motorista->id)
            ->get();

        $this->assertCount(1, $lotacoes);
        $this->assertSame($posto->id, $lotacoes->first()->escala_posto_id);
        $this->assertNull($lotacoes->first()->tipo_destino);
    }

    /** Colocar outro motorista na posicao libera o ocupante anterior. */
    #[Test]
    public function substituir_o_ocupante_da_posicao_libera_o_anterior(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();

        $antigo = Motorista::factory()->create();
        $novo = Motorista::factory()->create();

        $montador = app(MontadorDeEscala::class);
        $montador->lotarMotorista($posto, $antigo->id, 1);
        $montador->lotarMotorista($posto, $novo->id, 1);

        $lotacaoAntiga = EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->where('motorista_id', $antigo->id)
            ->first();

        $this->assertNull($lotacaoAntiga->escala_posto_id);
        $this->assertNull($lotacaoAntiga->tipo_destino);
        $this->assertSame(1, $posto->fresh()->lotacoes()->count());
    }

    /** A posicao precisa caber no ciclo do regime. */
    #[Test]
    public function recusa_posicao_fora_do_ciclo(): void
    {
        $unidade = Unidade::factory()->regime2448()->create(); // 3 posicoes
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();
        $motorista = Motorista::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A posição deve estar entre 1 e 3');

        app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, 4);
    }

    /** Definir destino remove os plantoes que o motorista tinha no mes. */
    #[Test]
    public function definir_destino_apaga_os_plantoes_do_motorista(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();
        $motoristas = Motorista::factory()->count(4)->create();

        $montador = app(MontadorDeEscala::class);

        foreach ($motoristas->values() as $i => $motorista) {
            $montador->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $afastado = $motoristas->first();
        $this->assertGreaterThan(0, EscalaPlantao::query()->where('motorista_id', $afastado->id)->count());

        $montador->definirDestino($escala->fresh(), $afastado->id, TipoDestino::Licenca);

        $this->assertSame(0, EscalaPlantao::query()->where('motorista_id', $afastado->id)->count());
    }

    /** Nao se cria duas escalas para o mesmo mes. */
    #[Test]
    public function recusa_escala_duplicada_no_mesmo_mes(): void
    {
        app(MontadorDeEscala::class)->criar(2026, 8);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Já existe uma escala');

        app(MontadorDeEscala::class)->criar(2026, 8);
    }

    /** A mesma ambulancia nao pode ocupar dois postos no mesmo mes. */
    #[Test]
    public function recusa_a_mesma_ambulancia_em_dois_postos(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        $ambulancia = Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('já está em outro posto');

        app(MontadorDeEscala::class)->adicionarPosto($escala, $unidade->id, $ambulancia->id);
    }

    /** Preenchimento automatico completa os postos com quem esta disponivel. */
    #[Test]
    public function preenche_as_vagas_automaticamente(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->count(2)->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        Motorista::factory()->count(10)->create();

        $preenchidas = app(MontadorDeEscala::class)->preencherVagasAutomaticamente($escala);

        $this->assertSame(8, $preenchidas); // 2 postos x 4 vagas
        $this->assertSame(0, $escala->fresh()->load('postos.lotacoes')->postos->sum(fn ($p) => $p->vagasLivres()));
    }

    /** Com efetivo curto, preenche o que consegue e para. */
    #[Test]
    public function preenche_o_que_consegue_quando_falta_motorista(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->count(2)->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        Motorista::factory()->count(5)->create();

        $preenchidas = app(MontadorDeEscala::class)->preencherVagasAutomaticamente($escala);

        $this->assertSame(5, $preenchidas);
        $this->assertSame(3, $escala->fresh()->load('postos.lotacoes')->postos->sum(fn ($p) => $p->vagasLivres()));
    }

    /** Ambulancia remanejada de unidade entre meses (requisito confirmado). */
    #[Test]
    public function permite_remanejar_a_ambulancia_de_unidade_no_mes_seguinte(): void
    {
        $upa = Unidade::factory()->regime2472()->create(['sigla' => 'UPA']);
        $praia = Unidade::factory()->regime2448()->create(['sigla' => 'PRAIA']);
        $ambulancia = Ambulancia::factory()->create(['unidade_id' => $upa->id]);

        $agosto = app(MontadorDeEscala::class)->criar(2026, 8);
        $this->assertSame('UPA', $agosto->postos()->first()->rotuloLotacao());

        $setembro = app(MontadorDeEscala::class)->criar(2026, 9);
        $posto = $setembro->postos()->first();

        // Remaneja o veiculo para a praia, adotando o regime da nova unidade.
        $posto->update([
            'unidade_id' => $praia->id,
            'rotulo' => $praia->sigla,
            'horas_trabalho' => $praia->horas_trabalho,
            'horas_descanso' => $praia->horas_descanso,
        ]);

        $posto->refresh();

        $this->assertSame('PRAIA', $posto->rotuloLotacao());
        $this->assertSame('24/48', $posto->regimeNotacao());
        $this->assertSame(3, $posto->vagas());
        $this->assertSame($ambulancia->id, $posto->ambulancia_id);
    }

    /** Uma unidade pode ter varias ambulancias, cada uma com sua equipe. */
    #[Test]
    public function permite_varias_ambulancias_na_mesma_unidade(): void
    {
        $sede = Unidade::factory()->regime2472()->create(['sigla' => 'SEDE']);
        Ambulancia::factory()->create(['unidade_id' => $sede->id, 'identificacao' => 'SEDE 1']);
        Ambulancia::factory()->create(['unidade_id' => $sede->id, 'identificacao' => 'SEDE 2']);
        Ambulancia::factory()->create(['unidade_id' => $sede->id, 'identificacao' => 'SEDE 3']);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $escala->load('postos');

        $this->assertCount(3, $escala->postos);
        $this->assertSame(
            ['SEDE 1', 'SEDE 2', 'SEDE 3'],
            $escala->postos->map(fn ($p) => $p->rotuloLotacao())->sort()->values()->all()
        );
        $this->assertSame(12, $escala->postos->sum(fn ($p) => $p->vagas()));
    }

    /** Sincronizar traz todo motorista ativo para a folha de lotacao do mes. */
    #[Test]
    public function sincroniza_o_efetivo_ativo_na_folha_do_mes(): void
    {
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        Motorista::factory()->count(4)->create();
        Motorista::factory()->inativo()->create();

        $novos = app(MontadorDeEscala::class)->sincronizarEfetivo($escala);

        $this->assertSame(4, $novos);
        $this->assertSame(4, $escala->lotacoes()->count());

        // Rodar de novo nao duplica.
        $this->assertSame(0, app(MontadorDeEscala::class)->sincronizarEfetivo($escala->fresh()));
    }

    #[Test]
    public function recusa_mes_invalido(): void
    {
        $this->expectException(RuntimeException::class);

        app(MontadorDeEscala::class)->criar(2026, 13);
    }
}
