<?php

namespace Tests\Feature\Escalas;

use App\Enums\StatusEscala;
use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\AnalisadorDeEfetivo;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalisadorDeEfetivoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requisito de dimensionamento: duas ambulancias em 24/72 e uma em 24/48
     * exigem 4 + 4 + 3 = 11 motoristas.
     */
    #[Test]
    public function calcula_as_vagas_a_partir_do_regime_de_cada_posto(): void
    {
        $escala = $this->criarEscala();
        $this->adicionarPosto($escala, descanso: 72);
        $this->adicionarPosto($escala, descanso: 72);
        $this->adicionarPosto($escala, descanso: 48);

        $analisador = AnalisadorDeEfetivo::para($escala->fresh()->load('postos'));

        $this->assertSame(11, $analisador->vagasNecessarias());
    }

    /** Requisito 10: alerta quando falta motorista para montar as escalas. */
    #[Test]
    public function alerta_quando_o_efetivo_e_insuficiente(): void
    {
        $escala = $this->criarEscala();
        $this->adicionarPosto($escala, descanso: 72); // exige 4
        Motorista::factory()->count(2)->create();     // tem 2

        $alertas = AnalisadorDeEfetivo::para($escala->fresh()->load('postos', 'lotacoes'))->alertas();

        $codigos = array_map(fn ($a) => $a->codigo, $alertas);

        $this->assertContains('efetivo_insuficiente', $codigos);
        $this->assertTrue(
            collect($alertas)->firstWhere('codigo', 'efetivo_insuficiente')->ehErro()
        );
    }

    /** Requisito 10: motorista ativo que "sobrou" precisa de destino. */
    #[Test]
    public function aponta_motoristas_ativos_sem_destino_definido(): void
    {
        $escala = $this->criarEscala();
        $posto = $this->adicionarPosto($escala, descanso: 72);

        $motoristas = Motorista::factory()->count(6)->create();

        // Lota apenas 4 dos 6.
        foreach ($motoristas->take(4)->values() as $i => $motorista) {
            EscalaLotacao::query()->create([
                'escala_id' => $escala->id,
                'motorista_id' => $motorista->id,
                'escala_posto_id' => $posto->id,
                'posicao' => $i + 1,
            ]);
        }

        $analisador = AnalisadorDeEfetivo::para($escala->fresh()->load('postos.lotacoes', 'lotacoes'));

        $this->assertSame(2, $analisador->motoristasSemDefinicao()->count());
        $this->assertContains(
            'sem_definicao',
            array_map(fn ($a) => $a->codigo, $analisador->alertas())
        );
    }

    /** Depois de dar destino a todos, o alerta de pendencia desaparece. */
    #[Test]
    public function nao_alerta_quando_todos_tem_destino(): void
    {
        $escala = $this->criarEscala();
        $posto = $this->adicionarPosto($escala, descanso: 72);
        $motoristas = Motorista::factory()->count(6)->create();

        foreach ($motoristas->take(4)->values() as $i => $motorista) {
            EscalaLotacao::query()->create([
                'escala_id' => $escala->id,
                'motorista_id' => $motorista->id,
                'escala_posto_id' => $posto->id,
                'posicao' => $i + 1,
            ]);
        }

        app(MontadorDeEscala::class)->definirRestantesComoReserva($escala->fresh()->load('postos', 'lotacoes'));

        $analisador = AnalisadorDeEfetivo::para($escala->fresh()->load('postos.lotacoes', 'lotacoes'));

        $this->assertSame(0, $analisador->motoristasSemDefinicao()->count());
        $this->assertSame(2, $analisador->totalDisponiveis());
        $this->assertNotContains(
            'sem_definicao',
            array_map(fn ($a) => $a->codigo, $analisador->alertas())
        );
    }

    /** Motorista de ferias nao pode ser sugerido para preencher vaga. */
    #[Test]
    public function nao_oferece_motorista_afastado_para_lotar(): void
    {
        $escala = $this->criarEscala();
        $this->adicionarPosto($escala, descanso: 72);

        $emFerias = Motorista::factory()->create();
        $reserva = Motorista::factory()->create();

        app(MontadorDeEscala::class)->definirDestino($escala, $emFerias->id, TipoDestino::Ferias);
        app(MontadorDeEscala::class)->definirDestino($escala, $reserva->id, TipoDestino::Reserva);

        $disponiveis = AnalisadorDeEfetivo::para($escala->fresh()->load('postos', 'lotacoes'))
            ->motoristasDisponiveisParaLotar()
            ->pluck('id');

        // A reserva pode ser convocada; quem esta de ferias, nao.
        $this->assertTrue($disponiveis->contains($reserva->id));
        $this->assertFalse($disponiveis->contains($emFerias->id));
    }

    /** Motorista inativo nao entra na conta do efetivo. */
    #[Test]
    public function ignora_motoristas_inativos(): void
    {
        $escala = $this->criarEscala();
        Motorista::factory()->count(3)->create();
        Motorista::factory()->count(2)->inativo()->create();

        $this->assertSame(3, AnalisadorDeEfetivo::para($escala->load('postos'))->totalAtivos());
    }

    /** Sem nenhum motorista de reserva, faltas ficam sem cobertura. */
    #[Test]
    public function avisa_quando_nao_ha_reserva(): void
    {
        $escala = $this->criarEscala();
        $posto = $this->adicionarPosto($escala, descanso: 72);
        $motoristas = Motorista::factory()->count(4)->create();

        foreach ($motoristas->values() as $i => $motorista) {
            EscalaLotacao::query()->create([
                'escala_id' => $escala->id,
                'motorista_id' => $motorista->id,
                'escala_posto_id' => $posto->id,
                'posicao' => $i + 1,
            ]);
        }

        $alertas = AnalisadorDeEfetivo::para($escala->fresh()->load('postos.lotacoes', 'lotacoes'))->alertas();

        $this->assertContains('sem_reserva', array_map(fn ($a) => $a->codigo, $alertas));
    }

    /** O saldo mostra de forma direta se sobra ou falta gente no mes. */
    #[Test]
    public function calcula_o_saldo_do_efetivo(): void
    {
        $escala = $this->criarEscala();
        $this->adicionarPosto($escala, descanso: 48); // exige 3
        Motorista::factory()->count(5)->create();

        $this->assertSame(2, AnalisadorDeEfetivo::para($escala->fresh()->load('postos'))->saldo());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarEscala(): Escala
    {
        return Escala::query()->create([
            'ano' => 2026,
            'mes' => 8,
            'status' => StatusEscala::Rascunho,
        ]);
    }

    private function adicionarPosto(Escala $escala, int $descanso): EscalaPosto
    {
        $unidade = Unidade::factory()->create([
            'horas_trabalho' => 24,
            'horas_descanso' => $descanso,
        ]);

        return EscalaPosto::query()->create([
            'escala_id' => $escala->id,
            'unidade_id' => $unidade->id,
            'ambulancia_id' => Ambulancia::factory()->create(['unidade_id' => $unidade->id])->id,
            'horas_trabalho' => 24,
            'horas_descanso' => $descanso,
            'rotulo' => $unidade->sigla,
            'ordem' => 10,
        ]);
    }
}
