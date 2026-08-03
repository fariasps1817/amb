<?php

namespace Tests\Feature\Escalas;

use App\Enums\StatusEscala;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPlantao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\GeradorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeradorDeEscalaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O caso descrito pela coordenacao: UPA em 24/72, quatro motoristas, André
     * no dia 01, Paulo no 02, Ricardo no 03, Luiz no 04 e André novamente no 05.
     */
    #[Test]
    public function gira_a_fila_de_quatro_motoristas_no_regime_24_72(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $nomes = ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ'];
        $this->lotar($posto, $nomes);

        app(GeradorDeEscala::class)->gerar($posto->escala);

        $this->assertSame('ANDRÉ', $this->motoristaNoDia($posto, '2026-08-01'));
        $this->assertSame('PAULO', $this->motoristaNoDia($posto, '2026-08-02'));
        $this->assertSame('RICARDO', $this->motoristaNoDia($posto, '2026-08-03'));
        $this->assertSame('LUIZ', $this->motoristaNoDia($posto, '2026-08-04'));
        $this->assertSame('ANDRÉ', $this->motoristaNoDia($posto, '2026-08-05'));
        $this->assertSame('PAULO', $this->motoristaNoDia($posto, '2026-08-06'));

        // Agosto tem 31 dias, um plantao por dia.
        $this->assertSame(31, $posto->plantoes()->count());
    }

    /** No regime 24/48 a fila tem tres motoristas e gira a cada tres dias. */
    #[Test]
    public function gira_a_fila_de_tres_motoristas_no_regime_24_48(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 48);
        $this->lotar($posto, ['JOSÉ', 'JOÃO', 'MARIA']);

        app(GeradorDeEscala::class)->gerar($posto->escala);

        $this->assertSame('JOSÉ', $this->motoristaNoDia($posto, '2026-08-01'));
        $this->assertSame('JOÃO', $this->motoristaNoDia($posto, '2026-08-02'));
        $this->assertSame('MARIA', $this->motoristaNoDia($posto, '2026-08-03'));
        $this->assertSame('JOSÉ', $this->motoristaNoDia($posto, '2026-08-04'));
        $this->assertSame('JOÃO', $this->motoristaNoDia($posto, '2026-08-05'));
    }

    /**
     * Cada motorista de um 24/72 trabalha sempre de quatro em quatro dias.
     */
    #[Test]
    public function cada_motorista_trabalha_a_cada_quatro_dias(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $this->lotar($posto, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);

        app(GeradorDeEscala::class)->gerar($posto->escala);

        $datas = EscalaPlantao::query()
            ->where('escala_posto_id', $posto->id)
            ->whereHas('motorista', fn ($q) => $q->where('nome_curto', 'ANDRÉ'))
            ->orderBy('data')
            ->pluck('data');

        $this->assertSame(
            ['01/08', '05/08', '09/08', '13/08', '17/08', '21/08', '25/08', '29/08'],
            $datas->map(fn ($d) => $d->format('d/m'))->all()
        );
    }

    /**
     * O ponto mais delicado do sistema: virar o mes sem quebrar o descanso.
     *
     * Agosto/2026 tem 31 dias, que nao e multiplo de 4. A fila termina no meio
     * do ciclo — dia 31 cai na posicao 3 — e setembro precisa retomar na
     * posicao 4, e nao reiniciar no primeiro motorista.
     */
    #[Test]
    public function continua_a_rotacao_do_mes_anterior(): void
    {
        $agosto = $this->criarPosto(2026, 8, descanso: 72);
        $motoristas = $this->lotar($agosto, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);

        app(GeradorDeEscala::class)->gerar($agosto->escala);

        // Ancora da virada: 31/08 e a posicao 3 (((31 - 1) mod 4) + 1).
        $ultimo = EscalaPlantao::query()
            ->where('escala_posto_id', $agosto->id)
            ->orderByDesc('data')
            ->first();

        $this->assertSame('2026-08-31', $ultimo->data->toDateString());
        $this->assertSame(3, $ultimo->posicao);
        $this->assertSame('RICARDO', $ultimo->motorista->nome_curto);

        // Setembro com a mesma ambulancia e a mesma equipe.
        $setembro = $this->criarPosto(2026, 9, descanso: 72, ambulancia: $agosto->ambulancia, unidade: $agosto->unidade);
        $this->lotarExistentes($setembro, $motoristas);

        app(GeradorDeEscala::class)->gerar($setembro->escala);

        // A fila retoma de onde parou, sem reiniciar no André.
        $this->assertSame('LUIZ', $this->motoristaNoDia($setembro, '2026-09-01'));
        $this->assertSame('ANDRÉ', $this->motoristaNoDia($setembro, '2026-09-02'));
        $this->assertSame('PAULO', $this->motoristaNoDia($setembro, '2026-09-03'));
        $this->assertSame('RICARDO', $this->motoristaNoDia($setembro, '2026-09-04'));

        // Ricardo trabalhou 31/08 e so volta em 04/09: 72h de descanso.
        $this->assertSame(
            4,
            $this->diasEntrePlantoes($motoristas['RICARDO'], '2026-08-31', '2026-09-04')
        );
    }

    /**
     * A prova definitiva da continuidade: atravessando tres meses seguidos,
     * nenhum motorista tem dois plantoes com menos de quatro dias de intervalo.
     */
    #[Test]
    public function mantem_o_intervalo_de_descanso_em_todas_as_viradas_de_mes(): void
    {
        $julho = $this->criarPosto(2026, 7, descanso: 72);
        $motoristas = $this->lotar($julho, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);
        app(GeradorDeEscala::class)->gerar($julho->escala);

        foreach ([8, 9] as $mes) {
            $posto = $this->criarPosto(2026, $mes, descanso: 72, ambulancia: $julho->ambulancia, unidade: $julho->unidade);
            $this->lotarExistentes($posto, $motoristas);
            app(GeradorDeEscala::class)->gerar($posto->escala);
        }

        foreach ($motoristas as $nome => $motorista) {
            $datas = EscalaPlantao::query()
                ->where('motorista_id', $motorista->id)
                ->orderBy('data')
                ->pluck('data');

            $this->assertGreaterThan(20, $datas->count(), "{$nome} deveria ter plantões nos três meses.");

            for ($i = 1; $i < $datas->count(); $i++) {
                $intervalo = (int) $datas[$i - 1]->diffInDays($datas[$i]);

                $this->assertSame(
                    4,
                    $intervalo,
                    sprintf(
                        '%s tem %d dia(s) entre %s e %s; o regime 24/72 exige 4.',
                        $nome,
                        $intervalo,
                        $datas[$i - 1]->format('d/m/Y'),
                        $datas[$i]->format('d/m/Y'),
                    )
                );
            }
        }
    }

    /**
     * Quando o mes anterior termina no meio do ciclo, a fila retoma de onde
     * parou. Julho tem 31 dias; se julho terminou na posicao 2, agosto comeca
     * na 3.
     */
    #[Test]
    public function retoma_a_fila_no_meio_do_ciclo(): void
    {
        $julho = $this->criarPosto(2026, 7, descanso: 72);
        $motoristas = $this->lotar($julho, ['A', 'B', 'C', 'D']);

        // Forca julho a terminar na posicao 2 comecando o mes na posicao 3.
        $julho->update(['continuar_rotacao' => false]);
        app(GeradorDeEscala::class)->gerar($julho->escala);

        $ultimoJulho = EscalaPlantao::query()
            ->where('escala_posto_id', $julho->id)
            ->orderByDesc('data')
            ->first();

        $posicaoFinal = $ultimoJulho->posicao;
        $posicaoEsperadaEmAgosto = ($posicaoFinal % 4) + 1;
        $nomeEsperado = ['A', 'B', 'C', 'D'][$posicaoEsperadaEmAgosto - 1];

        $agosto = $this->criarPosto(2026, 8, descanso: 72, ambulancia: $julho->ambulancia, unidade: $julho->unidade);
        $this->lotarExistentes($agosto, $motoristas);

        app(GeradorDeEscala::class)->gerar($agosto->escala);

        $this->assertSame($nomeEsperado, $this->motoristaNoDia($agosto, '2026-08-01'));
    }

    /** Quando o posto e marcado para reiniciar, o dia 1o pega a posicao 1. */
    #[Test]
    public function reinicia_a_fila_quando_configurado(): void
    {
        $agosto = $this->criarPosto(2026, 8, descanso: 72);
        $motoristas = $this->lotar($agosto, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);
        app(GeradorDeEscala::class)->gerar($agosto->escala);

        $setembro = $this->criarPosto(2026, 9, descanso: 72, ambulancia: $agosto->ambulancia, unidade: $agosto->unidade);
        $setembro->update(['continuar_rotacao' => false]);
        $this->lotarExistentes($setembro, $motoristas);

        app(GeradorDeEscala::class)->gerar($setembro->escala);

        $this->assertSame('ANDRÉ', $this->motoristaNoDia($setembro, '2026-09-01'));
    }

    /**
     * Requisito 7: um posto pode entrar em operacao no meio do mes, por exemplo
     * uma ambulancia entregue no dia 04.
     */
    #[Test]
    public function respeita_o_inicio_de_vigencia_no_meio_do_mes(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $posto->update(['data_inicio' => '2026-08-04']);
        $this->lotar($posto, ['JOSÉ', 'PAULO', 'RICARDO', 'LUIZ']);

        app(GeradorDeEscala::class)->gerar($posto->escala);

        $this->assertNull($this->motoristaNoDia($posto, '2026-08-03'));
        $this->assertSame('JOSÉ', $this->motoristaNoDia($posto, '2026-08-04'));
        $this->assertSame('PAULO', $this->motoristaNoDia($posto, '2026-08-05'));

        // Do dia 04 ao 31 sao 28 dias.
        $this->assertSame(28, $posto->plantoes()->count());
    }

    /** Postos com regimes diferentes convivem na mesma escala. */
    #[Test]
    public function gera_postos_com_regimes_diferentes_na_mesma_escala(): void
    {
        $upa = $this->criarPosto(2026, 8, descanso: 72);
        $escala = $upa->escala;

        $praia = EscalaPosto::query()->create([
            'escala_id' => $escala->id,
            'unidade_id' => Unidade::factory()->regime2448()->create()->id,
            'ambulancia_id' => Ambulancia::factory()->create()->id,
            'horas_trabalho' => 24,
            'horas_descanso' => 48,
            'rotulo' => 'PRAIA',
            'ordem' => 20,
        ]);

        $this->lotar($upa, ['U1', 'U2', 'U3', 'U4']);
        $this->lotar($praia, ['P1', 'P2', 'P3']);

        app(GeradorDeEscala::class)->gerar($escala);

        $this->assertSame('U1', $this->motoristaNoDia($upa, '2026-08-05'));   // ciclo de 4
        $this->assertSame('P1', $this->motoristaNoDia($praia, '2026-08-04')); // ciclo de 3
        $this->assertSame(62, $escala->plantoes()->count());                  // 31 + 31
    }

    /** Regerar a escala nao apaga trocas combinadas manualmente. */
    #[Test]
    public function preserva_ajustes_manuais_ao_regerar(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $this->lotar($posto, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);
        app(GeradorDeEscala::class)->gerar($posto->escala);

        $substituto = Motorista::factory()->create(['nome_curto' => 'SUBSTITUTO']);

        EscalaPlantao::query()
            ->where('escala_posto_id', $posto->id)
            ->whereDate('data', '2026-08-02')
            ->update(['motorista_id' => $substituto->id, 'ajuste_manual' => true]);

        $resultado = app(GeradorDeEscala::class)->gerar($posto->escala->fresh());

        $this->assertSame('SUBSTITUTO', $this->motoristaNoDia($posto, '2026-08-02'));
        $this->assertSame(1, $resultado->ajustesPreservados);
        $this->assertSame(31, $posto->plantoes()->count());
    }

    /** Com a opcao de descartar, o ajuste manual e sobrescrito. */
    #[Test]
    public function descarta_ajustes_manuais_quando_solicitado(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $this->lotar($posto, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);
        app(GeradorDeEscala::class)->gerar($posto->escala);

        $substituto = Motorista::factory()->create(['nome_curto' => 'SUBSTITUTO']);
        EscalaPlantao::query()
            ->where('escala_posto_id', $posto->id)
            ->whereDate('data', '2026-08-02')
            ->update(['motorista_id' => $substituto->id, 'ajuste_manual' => true]);

        app(GeradorDeEscala::class)->gerar($posto->escala->fresh(), descartarAjustesManuais: true);

        $this->assertSame('PAULO', $this->motoristaNoDia($posto, '2026-08-02'));
    }

    /** Posto incompleto gera aviso e deixa dias sem cobertura. */
    #[Test]
    public function avisa_quando_o_posto_nao_tem_motoristas_suficientes(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $this->lotar($posto, ['ANDRÉ', 'PAULO']); // faltam 2 de 4

        $resultado = app(GeradorDeEscala::class)->gerar($posto->escala);

        $this->assertGreaterThan(0, $resultado->diasDescobertos);
        $this->assertNotEmpty(array_filter(
            $resultado->alertas,
            fn ($a) => $a->codigo === 'posto_incompleto'
        ));

        // Somente as posicoes 1 e 2 foram cobertas.
        $this->assertSame('ANDRÉ', $this->motoristaNoDia($posto, '2026-08-01'));
        $this->assertSame('PAULO', $this->motoristaNoDia($posto, '2026-08-02'));
        $this->assertNull($this->motoristaNoDia($posto, '2026-08-03'));
        $this->assertNull($this->motoristaNoDia($posto, '2026-08-04'));
        $this->assertSame('ANDRÉ', $this->motoristaNoDia($posto, '2026-08-05'));
    }

    /** Escala sem nenhum posto nao gera nada e retorna erro. */
    #[Test]
    public function reporta_erro_quando_a_escala_nao_tem_postos(): void
    {
        $escala = Escala::query()->create([
            'ano' => 2026,
            'mes' => 8,
            'status' => StatusEscala::Rascunho,
        ]);

        $resultado = app(GeradorDeEscala::class)->gerar($escala);

        $this->assertTrue($resultado->temErros());
        $this->assertSame(0, $resultado->plantoesCriados);
    }

    /** A contagem de plantoes previstos alimenta a lista mensal de ocorrencias. */
    #[Test]
    public function calcula_os_plantoes_previstos_de_cada_motorista(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $this->lotar($posto, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);

        app(GeradorDeEscala::class)->gerar($posto->escala);

        $previstos = EscalaLotacao::query()
            ->where('escala_id', $posto->escala_id)
            ->with('motorista')
            ->get()
            ->mapWithKeys(fn ($l) => [$l->motorista->nome_curto => $l->plantoes_previstos]);

        // Agosto/2026 tem 31 dias: 8 plantoes para as posicoes 1 a 3 e 7 para a 4.
        $this->assertSame(8, $previstos['ANDRÉ']);
        $this->assertSame(8, $previstos['PAULO']);
        $this->assertSame(8, $previstos['RICARDO']);
        $this->assertSame(7, $previstos['LUIZ']);
        $this->assertSame(31, array_sum($previstos->all()));
    }

    /** A pre-visualizacao mostra a fila sem gravar nada no banco. */
    #[Test]
    public function preve_a_fila_sem_gravar(): void
    {
        $posto = $this->criarPosto(2026, 8, descanso: 72);
        $this->lotar($posto, ['ANDRÉ', 'PAULO', 'RICARDO', 'LUIZ']);

        $previsao = app(GeradorDeEscala::class)->prever($posto);

        $this->assertSame('ANDRÉ', $previsao['2026-08-01']['motorista']->nome_curto);
        $this->assertSame('LUIZ', $previsao['2026-08-04']['motorista']->nome_curto);
        $this->assertCount(31, $previsao);
        $this->assertSame(0, EscalaPlantao::query()->count());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarPosto(
        int $ano,
        int $mes,
        int $descanso = 72,
        ?Ambulancia $ambulancia = null,
        ?Unidade $unidade = null,
    ): EscalaPosto {
        $escala = Escala::query()->create([
            'ano' => $ano,
            'mes' => $mes,
            'status' => StatusEscala::Rascunho,
        ]);

        $unidade ??= Unidade::factory()->create([
            'horas_trabalho' => 24,
            'horas_descanso' => $descanso,
        ]);

        $ambulancia ??= Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        return EscalaPosto::query()->create([
            'escala_id' => $escala->id,
            'unidade_id' => $unidade->id,
            'ambulancia_id' => $ambulancia->id,
            'horas_trabalho' => 24,
            'horas_descanso' => $descanso,
            'rotulo' => $unidade->sigla,
            'continuar_rotacao' => true,
            'ordem' => 10,
        ]);
    }

    /**
     * Cria os motoristas e os lota nas posicoes 1..N do posto.
     *
     * @param  array<int, string>  $nomes
     * @return array<string, Motorista>
     */
    private function lotar(EscalaPosto $posto, array $nomes): array
    {
        $motoristas = [];

        foreach ($nomes as $indice => $nome) {
            $motorista = Motorista::factory()->create([
                'nome_curto' => $nome,
                'nome_completo' => $nome.' DE TESTE',
            ]);

            EscalaLotacao::query()->create([
                'escala_id' => $posto->escala_id,
                'motorista_id' => $motorista->id,
                'escala_posto_id' => $posto->id,
                'posicao' => $indice + 1,
            ]);

            $motoristas[$nome] = $motorista;
        }

        $posto->load('lotacoes.motorista');

        return $motoristas;
    }

    /**
     * Lota motoristas ja existentes, preservando a ordem das posicoes.
     *
     * @param  array<string, Motorista>  $motoristas
     */
    private function lotarExistentes(EscalaPosto $posto, array $motoristas): void
    {
        $posicao = 1;

        foreach ($motoristas as $motorista) {
            EscalaLotacao::query()->create([
                'escala_id' => $posto->escala_id,
                'motorista_id' => $motorista->id,
                'escala_posto_id' => $posto->id,
                'posicao' => $posicao++,
            ]);
        }

        $posto->load('lotacoes.motorista');
    }

    /** Confere que o motorista tem plantao nas duas datas e retorna o intervalo. */
    private function diasEntrePlantoes(Motorista $motorista, string $de, string $ate): int
    {
        $datas = EscalaPlantao::query()
            ->where('motorista_id', $motorista->id)
            ->whereIn('data', [$de, $ate])
            ->orderBy('data')
            ->pluck('data');

        $this->assertCount(2, $datas, "Esperava plantões em {$de} e {$ate}.");

        return (int) $datas[0]->diffInDays($datas[1]);
    }

    private function motoristaNoDia(EscalaPosto $posto, string $data): ?string
    {
        return EscalaPlantao::query()
            ->where('escala_posto_id', $posto->id)
            ->whereDate('data', $data)
            ->with('motorista')
            ->first()
            ?->motorista
            ?->nome_curto;
    }
}
