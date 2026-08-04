<?php

namespace Tests\Feature;

use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Models\User;
use App\Services\Documentos\DadosDaPlanilha;
use App\Services\Documentos\GeradorDeDocumentos;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Emissao dos tres documentos oficiais.
 *
 * Os testes verificam tanto os dados montados (que e onde erros de regra
 * apareceriam) quanto a geracao efetiva do PDF.
 */
class DocumentosTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operador = User::factory()->admin()->create();
        $this->actingAs($this->operador);

        Configuracao::atual()->update([
            'municipio' => 'Cascavel',
            'prefeitura' => 'Prefeitura Municipal de Cascavel',
            'secretaria' => 'Secretaria Municipal de Saúde',
            'setor' => 'Coordenação de Ambulâncias',
            'cidade' => 'Cascavel',
            'uf' => 'CE',
            'telefone_1' => '8533342244',
            'responsavel_setor' => 'Fulano de Tal',
            'cargo_responsavel' => 'Coordenador',
            'rodape_documentos' => 'PMC - SMS - COORD AMBULÂNCIAS',
        ]);
    }

    // -----------------------------------------------------------------
    // Documento 1 — planilha
    // -----------------------------------------------------------------

    #[Test]
    public function monta_os_blocos_da_planilha_por_ambulancia(): void
    {
        $escala = $this->escalaCompleta();

        $dados = DadosDaPlanilha::para($escala);

        $this->assertCount(1, $dados->blocos);
        $this->assertCount(31, $dados->dias);

        $bloco = $dados->blocos->first();

        // 24/72 gera quatro linhas, uma por posicao do ciclo.
        $this->assertCount(4, $bloco['linhas']);
        $this->assertSame('24/72', $bloco['regime']);
        $this->assertSame(4, $dados->totalLinhas());

        // A primeira posicao pega o dia 1o e volta de quatro em quatro dias.
        $diasDaPrimeira = array_keys($bloco['linhas'][0]['dias']);
        sort($diasDaPrimeira);

        $this->assertContains('2026-08-01', $diasDaPrimeira);
        $this->assertContains('2026-08-05', $diasDaPrimeira);
        $this->assertNotContains('2026-08-02', $diasDaPrimeira);
        $this->assertCount(8, $diasDaPrimeira);
    }

    /**
     * Uma ambulancia nunca e quebrada entre duas paginas: o leitor precisa ver
     * as posicoes do ciclo juntas para entender o revezamento.
     */
    #[Test]
    public function pagina_a_planilha_sem_quebrar_ambulancia(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->count(5)->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        Motorista::factory()->count(20)->create();
        app(MontadorDeEscala::class)->preencherVagasAutomaticamente($escala);
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        // Capacidade de 10 linhas por folha: cabem 2 blocos de 4 por pagina.
        $paginas = DadosDaPlanilha::para($escala->fresh())->paginas(linhasPorPagina: 10);

        $this->assertCount(3, $paginas);

        foreach ($paginas as $pagina) {
            foreach ($pagina['blocos'] as $bloco) {
                $this->assertCount(4, $bloco['linhas'], 'Nenhum bloco deve perder linhas na paginação.');
            }
        }

        // A numeracao das linhas continua entre as paginas.
        $this->assertSame(1, $paginas[0]['primeiro_numero']);
        $this->assertSame(9, $paginas[1]['primeiro_numero']);
    }

    #[Test]
    public function gera_o_pdf_da_planilha(): void
    {
        $escala = $this->escalaCompleta();

        $resposta = $this->get(route('documentos.planilha', $escala));

        $resposta->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $resposta->getContent());
    }

    #[Test]
    public function gera_o_pdf_da_planilha_no_layout_agrupado(): void
    {
        $escala = $this->escalaCompleta();

        $resposta = $this->get(route('documentos.planilha', [$escala, 'layout' => 'agrupado']));

        $resposta->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $resposta->getContent());
    }

    /**
     * A paginacao logica precisa acompanhar o que cabe na folha.
     *
     * Se a pagina logica comportar mais linhas do que o papel, o dompdf quebra
     * por conta propria no meio de uma ambulancia e o rowspan de placa e lotacao
     * fica orfao na folha seguinte — foi assim que a planilha saiu ilegivel.
     * Comparar paginas logicas com paginas fisicas trava essa regressao.
     */
    #[Test]
    #[DataProvider('layoutsDaPlanilha')]
    public function a_quebra_de_pagina_nao_parte_uma_ambulancia(string $layout, int $capacidade, int $extrasPorBloco): void
    {
        // Frota grande o bastante para exigir mais de uma folha.
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->count(11)->create(['unidade_id' => $unidade->id]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        Motorista::factory()->count(44)->create();
        app(MontadorDeEscala::class)->preencherVagasAutomaticamente($escala);
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $escala = $escala->fresh();

        $paginasLogicas = DadosDaPlanilha::para($escala)
            ->paginas(linhasPorPagina: $capacidade, linhasExtrasPorBloco: $extrasPorBloco);

        // Sem a página final de fora de escala: aqui medimos só a grade.
        $pdf = app(GeradorDeDocumentos::class)->planilha($escala, $layout, false)->output();
        $paginasFisicas = preg_match_all('#/Type\s*/Page[^s]#', $pdf);

        $this->assertSame(
            count($paginasLogicas),
            $paginasFisicas,
            "No layout {$layout} o dompdf quebrou páginas por conta própria, partindo uma ambulância ao meio."
        );

        // Nenhum bloco perde linhas na paginação.
        foreach ($paginasLogicas as $pagina) {
            foreach ($pagina['blocos'] as $bloco) {
                $this->assertCount(4, $bloco['linhas']);
            }
        }
    }

    public static function layoutsDaPlanilha(): array
    {
        return [
            'clássico' => [GeradorDeDocumentos::LAYOUT_CLASSICO, 34, 0],
            'agrupado' => [GeradorDeDocumentos::LAYOUT_AGRUPADO, 32, 1],
        ];
    }

    // -----------------------------------------------------------------
    // Página final: condutores fora de escala
    // -----------------------------------------------------------------

    /**
     * A relação de quem está fora de escala fecha o quadro do efetivo na própria
     * planilha, que é como o setor envia ao RH.
     */
    #[Test]
    public function agrupa_os_condutores_fora_de_escala_por_situacao(): void
    {
        $escala = $this->escalaCompleta();
        $montador = app(MontadorDeEscala::class);

        $reserva = Motorista::factory()->create(['nome_completo' => 'BRUNO ALVES', 'nome_curto' => 'BRUNO']);
        $outraReserva = Motorista::factory()->create(['nome_completo' => 'ANA LIMA', 'nome_curto' => 'ANA']);
        $ferias = Motorista::factory()->create(['nome_completo' => 'CARLA DIAS', 'nome_curto' => 'CARLA']);

        $montador->definirDestino($escala, $reserva->id, TipoDestino::Reserva);
        $montador->definirDestino($escala, $outraReserva->id, TipoDestino::Reserva);
        $montador->definirDestino(
            $escala,
            $ferias->id,
            TipoDestino::Ferias,
            periodoInicio: '2026-08-01',
            periodoFim: '2026-08-30',
        );

        $grupos = DadosDaPlanilha::para($escala->fresh())->foraDeEscala();

        // Reserva vem antes de férias: quem está à disposição aparece primeiro.
        $this->assertSame(
            ['SOBREAVISO (RESERVA)', 'FÉRIAS'],
            $grupos->pluck('rotulo')->all()
        );

        $sobreaviso = $grupos->firstWhere('rotulo', 'SOBREAVISO (RESERVA)');
        $this->assertTrue($sobreaviso['disponivel']);
        $this->assertCount(2, $sobreaviso['linhas']);

        // Ordem alfabética dentro do grupo.
        $this->assertSame(
            ['ANA LIMA', 'BRUNO ALVES'],
            array_map(fn ($l) => $l['motorista']->nome_completo, $sobreaviso['linhas'])
        );

        // O período do afastamento é montado a partir das datas.
        $grupoFerias = $grupos->firstWhere('rotulo', 'FÉRIAS');
        $this->assertFalse($grupoFerias['disponivel']);
        $this->assertSame('01 a 30/08', $grupoFerias['linhas'][0]['periodo']);

        // O efetivo fecha: escalados + fora = total.
        $dados = DadosDaPlanilha::para($escala->fresh());
        $this->assertSame(4, $dados->totalLinhas());
        $this->assertSame(3, $dados->totalForaDeEscala());
    }

    /** Quem está escalado não pode aparecer na relação de fora de escala. */
    #[Test]
    public function nao_lista_quem_esta_escalado(): void
    {
        $escala = $this->escalaCompleta();
        $escalados = $escala->lotacoes->filter(fn ($l) => $l->escalado())->pluck('motorista_id');

        $grupos = DadosDaPlanilha::para($escala)->foraDeEscala();

        $listados = $grupos->flatMap(fn ($g) => array_map(fn ($l) => $l['motorista']->id, $g['linhas']));

        $this->assertEmpty($listados->intersect($escalados));
    }

    /** A página final entra por padrão e pode ser omitida. */
    #[Test]
    #[DataProvider('layoutsDaPlanilha')]
    public function pagina_de_fora_de_escala_e_opcional(string $layout): void
    {
        $escala = $this->escalaCompleta();
        app(MontadorDeEscala::class)->definirDestino(
            $escala,
            Motorista::factory()->create()->id,
            TipoDestino::Reserva
        );

        $gerador = app(GeradorDeDocumentos::class);
        $escala = $escala->fresh();

        $comAnexo = preg_match_all('#/Type\s*/Page[^s]#', $gerador->planilha($escala, $layout)->output());
        $semAnexo = preg_match_all('#/Type\s*/Page[^s]#', $gerador->planilha($escala, $layout, false)->output());

        $this->assertSame($semAnexo + 1, $comAnexo, "No layout {$layout} a página final não foi acrescentada.");
    }

    #[Test]
    public function a_rota_permite_omitir_a_pagina_final(): void
    {
        $escala = $this->escalaCompleta();

        // Sem o parâmetro, a página vem junto.
        $padrao = $this->get(route('documentos.planilha', $escala))->getContent();
        $omitida = $this->get(route('documentos.planilha', [$escala, 'fora_de_escala' => 0]))->getContent();

        $this->assertGreaterThan(
            preg_match_all('#/Type\s*/Page[^s]#', $omitida),
            preg_match_all('#/Type\s*/Page[^s]#', $padrao)
        );
    }

    /** Sem ninguém fora de escala, a página sai informando isso. */
    #[Test]
    public function pagina_final_com_todos_escalados(): void
    {
        $escala = $this->escalaCompleta();

        $this->assertSame(0, DadosDaPlanilha::para($escala)->totalForaDeEscala());

        // Continua gerando, sem quebrar.
        $this->get(route('documentos.planilha', $escala))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /** Um layout desconhecido cai no clássico, em vez de quebrar. */
    #[Test]
    public function layout_invalido_usa_o_classico(): void
    {
        $escala = $this->escalaCompleta();

        $this->get(route('documentos.planilha', [$escala, 'layout' => 'inexistente']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    #[Test]
    public function baixa_a_planilha_com_nome_do_mes(): void
    {
        $escala = $this->escalaCompleta();

        $this->get(route('documentos.planilha', [$escala, 'download' => 1]))
            ->assertOk()
            ->assertDownload('escala-agosto-2026.pdf');
    }

    // -----------------------------------------------------------------
    // Documento 2 — ocorrências
    // -----------------------------------------------------------------

    /**
     * A lista contempla todo o efetivo — escalados, reservas e afastados — em
     * ordem alfabetica que ignora acentos.
     */
    #[Test]
    public function lista_todo_o_efetivo_em_ordem_alfabetica(): void
    {
        // Nomes controlados nos escalados também: os da factory são aleatórios e
        // poderiam cair antes ou depois dos nomes verificados aqui.
        $escala = $this->escalaCompleta(nomes: ['MARCOS DIAS', 'NELSON ROCHA', 'OTÁVIO PINTO', 'PAULO VIEIRA']);

        $reserva = Motorista::factory()->create(['nome_completo' => 'ZULMIRA DOS SANTOS', 'nome_curto' => 'ZULMIRA']);
        $ferias = Motorista::factory()->create(['nome_completo' => 'ÁLVARO PEREIRA', 'nome_curto' => 'ÁLVARO']);

        $montador = app(MontadorDeEscala::class);
        $montador->definirDestino($escala, $reserva->id, TipoDestino::Reserva);
        $montador->definirDestino($escala, $ferias->id, TipoDestino::Ferias, observacao: 'Férias de 01 a 30/08/26');

        $linhas = app(GeradorDeDocumentos::class)->linhasDeOcorrencias($escala->fresh());

        // Seis servidores: quatro escalados, uma reserva e um de férias.
        $this->assertCount(6, $linhas);

        // ÁLVARO vem primeiro apesar do acento, e a ordem é alfabética.
        $this->assertSame(
            ['ÁLVARO PEREIRA', 'MARCOS DIAS', 'NELSON ROCHA', 'OTÁVIO PINTO', 'PAULO VIEIRA', 'ZULMIRA DOS SANTOS'],
            $linhas->pluck('nome')->all()
        );

        // A numeracao e sequencial a partir de 1.
        $this->assertSame([1, 2, 3, 4, 5, 6], $linhas->pluck('numero')->all());

        $linhaReserva = $linhas->firstWhere('nome', 'ZULMIRA DOS SANTOS');
        $this->assertSame('SOBREAVISO (RESERVA)', $linhaReserva['lotacao']);
        // Reserva tem previsao de zero plantoes.
        $this->assertSame(0, $linhaReserva['plantoes']);

        $linhaFerias = $linhas->firstWhere('nome', 'ÁLVARO PEREIRA');
        $this->assertSame('FÉRIAS', $linhaFerias['lotacao']);
        // Afastado nao tem previsao, o que e diferente de prever zero.
        $this->assertSame('~', $linhaFerias['plantoes']);
        $this->assertSame('Férias de 01 a 30/08/26', $linhaFerias['ocorrencia']);
    }

    #[Test]
    public function conta_os_plantoes_de_quem_esta_escalado(): void
    {
        $escala = $this->escalaCompleta();

        $linhas = app(GeradorDeDocumentos::class)->linhasDeOcorrencias($escala);
        $escalados = $linhas->where('plantoes', '!=', '~');

        // Agosto/2026 tem 31 dias distribuidos entre os quatro motoristas.
        $this->assertSame(31, $escalados->sum('plantoes'));
        $this->assertSame('CONTRATO', $linhas->first()['vinculo']);
    }

    #[Test]
    public function gera_o_pdf_de_ocorrencias(): void
    {
        $escala = $this->escalaCompleta();

        $resposta = $this->get(route('documentos.ocorrencias', $escala));

        $resposta->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $resposta->getContent());
    }

    // -----------------------------------------------------------------
    // Documento 3 — frequência
    // -----------------------------------------------------------------

    /**
     * A folha lista todos os dias do mes: nos de plantao fica o espaco para
     * assinar, nos demais sai a marca de folga.
     */
    #[Test]
    public function monta_a_folha_de_frequencia_com_todos_os_dias(): void
    {
        $escala = $this->escalaCompleta();

        $folhas = app(GeradorDeDocumentos::class)->folhasDeFrequencia($escala);

        // Uma folha por motorista escalado.
        $this->assertCount(4, $folhas);

        $folha = $folhas->first();

        $this->assertCount(31, $folha['linhas']);
        $this->assertSame('24/72', $folha['regime']);

        $comPlantao = collect($folha['linhas'])->where('plantao', true);
        $this->assertSame($folha['total_plantoes'], $comPlantao->count());
        // Em 24/72, cada motorista faz 7 ou 8 plantoes num mes de 31 dias.
        $this->assertContains($folha['total_plantoes'], [7, 8]);

        // O plantao de 24 horas termina no dia seguinte.
        $primeiroPlantao = $comPlantao->first();
        $this->assertSame('07:00', $primeiroPlantao['entrada_hora']);
        $this->assertSame('07:00', $primeiroPlantao['saida_hora']);
        $this->assertNotSame($primeiroPlantao['entrada_data'], $primeiroPlantao['saida_data']);
    }

    /**
     * A folha de um motorista de 24/72 tem 8 dias assinaveis e os demais
     * marcados como folga — nunca o contrario.
     */
    #[Test]
    public function marca_folga_apenas_nos_dias_sem_plantao(): void
    {
        $escala = $this->escalaCompleta();
        $motorista = $escala->plantoes()->orderBy('data')->first()->motorista;

        $folha = app(GeradorDeDocumentos::class)->folhasDeFrequencia($escala, $motorista)->first();

        $datasComPlantao = $escala->plantoes()
            ->where('motorista_id', $motorista->id)
            ->pluck('data')
            ->map(fn ($d) => $d->format('d/m'))
            ->all();

        foreach ($folha['linhas'] as $linha) {
            $esperado = in_array($linha['entrada_data'], $datasComPlantao, true);

            $this->assertSame(
                $esperado,
                $linha['plantao'],
                "O dia {$linha['entrada_data']} deveria ".($esperado ? 'ter plantão' : 'estar de folga').'.'
            );
        }

        // Entrada e saida sao preenchidas em todos os dias, como no documento
        // oficial; o que distingue o plantao e a linha de assinatura.
        $this->assertSame(
            31,
            collect($folha['linhas'])->filter(fn ($l) => filled($l['entrada_hora']) && filled($l['saida_hora']))->count()
        );
    }

    #[Test]
    public function gera_o_pdf_de_frequencias(): void
    {
        $escala = $this->escalaCompleta();

        $resposta = $this->get(route('documentos.frequencias', $escala));

        $resposta->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $resposta->getContent());
    }

    #[Test]
    public function gera_a_folha_de_um_motorista(): void
    {
        $escala = $this->escalaCompleta();
        $motorista = $escala->plantoes()->first()->motorista;

        $folhas = app(GeradorDeDocumentos::class)->folhasDeFrequencia($escala, $motorista);
        $this->assertCount(1, $folhas);

        $this->get(route('documentos.frequencia', [$escala, $motorista]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // -----------------------------------------------------------------
    // Casos de borda
    // -----------------------------------------------------------------

    /** Escala sem plantoes ainda emite os documentos, apenas vazios. */
    #[Test]
    public function emite_documentos_de_escala_sem_plantoes(): void
    {
        $unidade = Unidade::factory()->regime2472()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);
        $escala = app(MontadorDeEscala::class)->criar(2026, 8);

        $this->get(route('documentos.planilha', $escala))->assertOk();
        $this->get(route('documentos.ocorrencias', $escala))->assertOk();
        $this->get(route('documentos.frequencias', $escala))->assertOk();
    }

    /** Sem identidade institucional cadastrada, o cabecalho usa o texto. */
    #[Test]
    public function emite_documentos_sem_logos_cadastradas(): void
    {
        Configuracao::atual()->update([
            'logo_prefeitura' => null,
            'logo_secretaria' => null,
            'brasao' => null,
        ]);

        $escala = $this->escalaCompleta();

        $this->get(route('documentos.planilha', $escala))->assertOk();
    }

    /** Fevereiro tem 28 ou 29 dias e a planilha acompanha. */
    #[Test]
    public function respeita_a_quantidade_de_dias_do_mes(): void
    {
        $unidade = Unidade::factory()->regime2448()->create();
        Ambulancia::factory()->create(['unidade_id' => $unidade->id]);

        // 2028 e bissexto: fevereiro tem 29 dias.
        $escala = app(MontadorDeEscala::class)->criar(2028, 2);
        Motorista::factory()->count(3)->create();
        app(MontadorDeEscala::class)->preencherVagasAutomaticamente($escala);
        app(GeradorDeEscala::class)->gerar($escala->fresh());

        $dados = DadosDaPlanilha::para($escala->fresh());

        $this->assertCount(29, $dados->dias);
        $this->assertSame(29, $escala->fresh()->plantoes()->count());
    }

    #[Test]
    public function tela_de_documentos_abre(): void
    {
        $escala = $this->escalaCompleta();

        $this->get(route('documentos.index', $escala))
            ->assertOk()
            ->assertSee('Planilha de plantões')
            ->assertSee('Lista mensal de ocorrências')
            ->assertSee('Folhas de frequência');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /** Agosto/2026, uma ambulancia 24/72 com as quatro vagas e plantoes gerados. */
    /**
     * @param  array<int, string>|null  $nomes  Nomes dos quatro escalados. Informe
     *                                          quando o teste depender da ordem
     *                                          alfabética — os nomes da factory
     *                                          são aleatórios.
     */
    private function escalaCompleta(?array $nomes = null): Escala
    {
        $unidade = Unidade::factory()->regime2472()->create(['sigla' => 'UPA', 'nome' => 'UPA Centro']);
        Ambulancia::factory()->create([
            'unidade_id' => $unidade->id,
            'placa' => 'THQ4H34',
            'identificacao' => 'SEDE 1',
        ]);

        $escala = app(MontadorDeEscala::class)->criar(2026, 8);
        $posto = $escala->postos()->first();

        $motoristas = $nomes === null
            ? Motorista::factory()->count(4)->contratado()->create()
            : collect($nomes)->map(fn (string $nome) => Motorista::factory()->contratado()->create([
                'nome_completo' => $nome,
                'nome_curto' => $nome,
            ]));

        foreach ($motoristas->values() as $i => $motorista) {
            app(MontadorDeEscala::class)->lotarMotorista($posto, $motorista->id, $i + 1);
        }

        app(GeradorDeEscala::class)->gerar($escala->fresh());

        return $escala->fresh();
    }
}
