<?php

namespace Tests\Feature\Escalas;

use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Escalas\ValidadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ValidadorDeEscalaTest extends TestCase
{
    use RefreshDatabase;

    /** Escala completa e coerente pode ser publicada. */
    #[Test]
    public function aprova_uma_escala_completa(): void
    {
        $escala = $this->escalaCompleta();

        $codigos = $this->codigosDeErro($escala);

        $this->assertSame([], $codigos, 'Não deveria haver erros: '.implode(', ', $codigos));
        $this->assertTrue(ValidadorDeEscala::para($escala)->podePublicar());
    }

    /** Posto sem motorista suficiente deixa dias descobertos e barra a publicacao. */
    #[Test]
    public function reprova_escala_com_dias_sem_motorista(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();

        // Apenas 2 dos 4 motoristas exigidos.
        foreach (Motorista::factory()->count(2)->create()->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $codigos = $this->codigosDeErro($escala->fresh());

        $this->assertContains('dias_descobertos', $codigos);
        $this->assertContains('posto_incompleto', $codigos);
        $this->assertFalse(ValidadorDeEscala::para($escala->fresh())->podePublicar());
    }

    /**
     * A verificacao mais importante do validador: detectar descanso insuficiente
     * na virada do mes, que e onde o erro passa despercebido.
     */
    #[Test]
    public function detecta_descanso_insuficiente_na_virada_do_mes(): void
    {
        $escala = $this->escalaCompleta(2026, 9);
        $motorista = $escala->plantoes()->orderBy('data')->first()->motorista;

        // Simula um plantao em 31/08 para quem tambem pega 01/09.
        $primeiro = $escala->plantoes()->orderBy('data')->first();

        EscalaPlantao::query()->create([
            'escala_id' => $escala->id,
            'escala_posto_id' => $primeiro->escala_posto_id,
            'motorista_id' => $motorista->id,
            'data' => '2026-08-31',
            'posicao' => 1,
            'hora_entrada' => '07:00',
            'hora_saida' => '07:00',
            'ajuste_manual' => true,
        ]);

        $codigos = $this->codigosDeErro($escala->fresh());

        $this->assertContains('descanso_insuficiente', $codigos);
    }

    /** Motorista escalado em duas ambulancias no mesmo dia e erro grave. */
    #[Test]
    public function detecta_motorista_em_duas_ambulancias_no_mesmo_dia(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->count(2)->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $postos = $escala->postos;
        $motoristas = Motorista::factory()->count(8)->create();

        foreach ($postos as $indice => $posto) {
            foreach ($motoristas->slice($indice * 4, 4)->values() as $i => $motorista) {
                app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
            }
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        // Duplica o motorista do primeiro posto no segundo, no mesmo dia.
        $plantao = $escala->plantoes()->where('escala_posto_id', $postos[0]->id)->orderBy('data')->first();

        EscalaPlantao::query()
            ->where('escala_posto_id', $postos[1]->id)
            ->whereDate('data', $plantao->data)
            ->update(['motorista_id' => $plantao->motorista_id, 'ajuste_manual' => true]);

        $codigos = $this->codigosDeErro($escala->fresh());

        $this->assertContains('plantao_duplicado', $codigos);
    }

    /** CNH vencida no periodo impede o motorista de assumir plantao. */
    #[Test]
    public function detecta_motorista_com_cnh_vencida(): void
    {
        $unidade = Unidade::factory()->regime2448()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();

        $regulares = Motorista::factory()->count(2)->create();
        $vencido = Motorista::factory()->create(['cnh_validade' => '2026-07-15']);

        foreach ($regulares->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }
        app(MontadorDeEscala::class)->lotarMotorista($posto, $vencido->id, 3);

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $alertas = ValidadorDeEscala::para($escala->fresh())->validar();
        $inaptos = array_filter($alertas, fn ($a) => $a->codigo === 'motorista_inapto');

        $this->assertNotEmpty($inaptos);
        $this->assertStringContainsString('CNH vencida', reset($inaptos)->mensagem);
    }

    /** Posto sem ambulancia definida barra a publicacao. */
    #[Test]
    public function detecta_posto_sem_ambulancia(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $escala->postos()->first()->update(['ambulancia_id' => null]);

        $this->assertContains('posto_sem_ambulancia', $this->codigosDeErro($escala->fresh()));
    }

    /** Ambulancia inativa gera aviso, mas nao impede a publicacao. */
    #[Test]
    public function avisa_sobre_ambulancia_inativa_sem_barrar(): void
    {
        $escala = $this->escalaCompleta();
        $escala->postos()->first()->ambulancia->update(['ativo' => false]);

        $alertas = ValidadorDeEscala::para($escala->fresh())->validar();
        $codigos = array_map(fn ($a) => $a->codigo, $alertas);

        $this->assertContains('ambulancia_inativa', $codigos);
        $this->assertTrue(ValidadorDeEscala::para($escala->fresh())->podePublicar());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Escala pronta para publicar: uma ambulancia em 24/72, quatro motoristas
     * lotados, plantoes gerados e uma reserva para nao disparar o aviso de
     * ausencia de sobreaviso.
     */
    private function escalaCompleta(int $ano = 2026, int $mes = 8): Escala
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar($ano, $mes);
        $posto = $escala->postos()->first();

        foreach (Motorista::factory()->count(4)->create()->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        // Uma reserva, para o mes ter cobertura de faltas.
        app(MontadorDeEscala::class)->definirDestino(
            $escala,
            Motorista::factory()->create()->id,
            TipoDestino::Reserva
        );

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        return $escala->fresh();
    }

    /** @return array<int, string> */
    private function codigosDeErro(Escala $escala): array
    {
        return array_values(array_unique(array_map(
            fn ($a) => $a->codigo,
            array_filter(ValidadorDeEscala::para($escala)->validar(), fn ($a) => $a->ehErro())
        )));
    }
}
