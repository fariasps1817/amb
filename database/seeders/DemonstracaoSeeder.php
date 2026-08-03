<?php

namespace Database\Seeders;

use App\Enums\StatusMotorista;
use App\Enums\TipoDestino;
use App\Enums\Vinculo;
use App\Enums\VinculoAmbulancia;
use App\Models\Ambulancia;
use App\Models\Configuracao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Massa de demonstracao com a estrutura real do setor.
 *
 * Reproduz o cenario dos documentos em uso: onze ambulancias, a maioria em
 * 24/72 e duas em 24/48, com 49 motoristas — o suficiente para lotar todos os
 * postos e ainda sobrar gente para reserva, ferias e licenca.
 *
 * Rode com:  php artisan db:seed --class=DemonstracaoSeeder
 */
class DemonstracaoSeeder extends Seeder
{
    /**
     * Unidades e suas ambulancias, no formato:
     * sigla => [nome, tipo, regime, [identificacao => placa, ...]]
     */
    private const ESTRUTURA = [
        'SEDE' => [
            'nome' => 'Sede da Secretaria de Saúde',
            'tipo' => 'Sede',
            'regime' => '24/72',
            'frota' => ['SEDE 1' => 'THQ4H34', 'SEDE 2' => 'THQ4F19', 'SEDE 3' => 'THT3F28'],
        ],
        'GUANACES' => [
            'nome' => 'Posto de Saúde de Guanacés',
            'tipo' => 'Posto de Saúde',
            'regime' => '24/72',
            'frota' => ['GUANACES' => 'THQ4J09'],
        ],
        'BAR NOVA' => [
            'nome' => 'Posto de Saúde de Barra Nova',
            'tipo' => 'Posto de Saúde',
            'regime' => '24/72',
            'frota' => ['BAR NOVA' => 'SBQ9C28'],
        ],
        'CAPONGA' => [
            'nome' => 'Posto de Saúde da Caponga',
            'tipo' => 'Posto de Saúde',
            'regime' => '24/72',
            'frota' => ['CAPONGA' => 'SBQ8C48'],
        ],
        'BALBINO' => [
            'nome' => 'Posto de Saúde de Balbino',
            'tipo' => 'Posto de Saúde',
            'regime' => '24/72',
            'frota' => ['BALBINO' => 'HXV8S51'],
        ],
        'B. ÁGUA' => [
            'nome' => 'Posto de Saúde de Boa Água',
            'tipo' => 'Posto de Saúde',
            'regime' => '24/72',
            'frota' => ['B. ÁGUA' => 'THS6C18'],
        ],
        'BRITO' => [
            'nome' => 'Posto de Saúde de Brito',
            'tipo' => 'Posto de Saúde',
            'regime' => '24/72',
            'frota' => ['BRITO' => 'TYA5G82'],
        ],
        'PITOMBEIRAS' => [
            'nome' => 'Posto de Saúde de Pitombeiras',
            'tipo' => 'Distrito',
            'regime' => '24/48',
            'frota' => ['PITOMBEIRAS' => 'PNQ2G44'],
        ],
        'CRISTAIS' => [
            'nome' => 'Posto de Saúde de Cristais',
            'tipo' => 'Distrito',
            'regime' => '24/48',
            'frota' => ['CRISTAIS' => 'SBF0F73'],
        ],
    ];

    /** Nomes usados na demonstracao. */
    private const NOMES = [
        ['JOÃO BERNARDO DE OLIVEIRA', 'JOÃO BERNARDO', 'efetivo'],
        ['MARIA DA CONCEIÇÃO HOLANDA ASSIS', 'MARIA DA CONCEIÇÃO', 'efetivo'],
        ['JOSÉ LUIS CARNEIRO PINHEIRO', 'JOSÉ LUIS', 'contrato'],
        ['JOSÉ EDSON FERREIRA FERNANDES', 'EDSON FERREIRA', 'efetivo'],
        ['FRANCISCO CELIO DA SILVA', 'FRANCISCO CELIO', 'efetivo'],
        ['MARIA DIVANIR FERREIRA DA SILVA', 'MARIA DIVANIR', 'efetivo'],
        ['ERANDIR LOPES', 'ERANDIR LOPES', 'contrato'],
        ['FRANCISCO DELVANEI CASTRO MACHADO', 'FRANCISCO DELVANEI', 'efetivo'],
        ['CARLOS NOGUEIRA', 'CARLOS NOGUEIRA', 'efetivo'],
        ['EDCLEIA ARAUJO DOS SANTOS', 'EDCLEIA ARAUJO', 'contrato'],
        ['MARDONIO DA COSTA GONDIM', 'MARDONIO GONDIM', 'efetivo'],
        ['JOSEMI SILVA DE OLIVEIRA', 'JOSEMI SILVA', 'efetivo'],
        ['MARCILIO DANTAS', 'MARCILIO DANTAS', 'contrato'],
        ['CARLOS ALBERTO DANTAS LEMOS', 'CARLOS LEMOS', 'contrato'],
        ['IVONILDO GABRIEL MATIAS', 'IVONILDO GABRIEL', 'efetivo'],
        ['JOSÉ LEANDRO DA SILVA CARVALHO', 'JOSÉ LEANDRO', 'contrato'],
        ['FRANCISCO NILSIVAN GONDIM DE LIMA', 'FRANCISCO NILSIVAN', 'efetivo'],
        ['CRISBER MARTINS DA SILVA', 'CRISBER MARTINS', 'contrato'],
        ['ILBERTH NUNES DOS SANTOS', 'ILBERTH NUNES', 'contrato'],
        ['RONALDO LOPES DE CASTRO', 'RONALDO CASTRO', 'contrato'],
        ['MARCUS VENICIUS BEZERRA DE OLIVEIRA', 'MARCUS VENICIUS', 'contrato'],
        ['FRANCISCO RUAN ALMEIDA SENA', 'FRANCISCO RUAN', 'contrato'],
        ['CARLOS ALBERTO GOMES DA SILVA', 'CARLOS ALBERTO', 'contrato'],
        ['JOSÉ ADRIANO RIBEIRO DA SILVA', 'JOSÉ ADRIANO', 'contrato'],
        ['RONALDO NASCIMENTO DE MATOS', 'RONALDO MATOS', 'contrato'],
        ['CLEZIO SOUZA FAUSTINO', 'CLEZIO FAUSTINO', 'contrato'],
        ['ANTONIO LUCIO LIMA', 'LÚCIO LIMA', 'contrato'],
        ['JOSÉ RIBAMAR PINHEIRO CARDOSO', 'RIBAMAR CARDOSO', 'contrato'],
        ['EDNARDO LIMA DE OLIVEIRA', 'EDNARDO LIMA', 'contrato'],
        ['CESAR DA SILVA SANTOS', 'CESAR SILVA', 'contrato'],
        ['ELINO DA SILVA LOPES', 'ELINO LOPES', 'contrato'],
        ['EDNALDO DE SOUSA LEMOS ANDRADE', 'EDNALDO ANDRADE', 'contrato'],
        ['FRANCISCO VIEIRA DA SILVA FILHO', 'VIEIRA FILHO', 'contrato'],
        ['ERONILSON PEREIRA DA SILVA', 'ERONILSON SILVA', 'contrato'],
        ['ILIAN DE SOUSA SANTOS', 'ILIAN SANTOS', 'contrato'],
        ['TACIELIO VIEIRA DA SILVA', 'TACIELIO VIEIRA', 'contrato'],
        ['JOSÉ CLAUDENOR DELMIRO', 'JOSÉ CLAUDENOR', 'contrato'],
        ['JOSÉ WILLAN CARNEIRO DA SILVA', 'JOSÉ WILLAN', 'contrato'],
        ['JOÃO LOPES DA SILVA NETO', 'JOÃO LOPES', 'contrato'],
        ['JOSÉ NUNES DE SOUZA NETO', 'JOSÉ NETO', 'contrato'],
        ['JOÃO MAIA JUNIOR', 'JOÃO JUNIOR', 'contrato'],
        ['JOSÉ IVAN PEREIRA', 'JOSÉ IVAN', 'contrato'],
        // Efetivo que sobra: reserva, apoio e afastamentos.
        ['ANTONIO QUEIROZ LEITE', 'ANTONIO QUEIROZ', 'efetivo'],
        ['JOÃO BOSCO SILVA PEREIRA', 'JOÃO BOSCO', 'efetivo'],
        ['JOSÉ CARLOS FREITAS DE OLIVEIRA', 'JOSÉ CARLOS', 'efetivo'],
        ['FRANCISCO IVAN DA SILVA', 'FRANCISCO IVAN', 'efetivo'],
        ['TIAGO SILVA SOUSA', 'TIAGO SOUSA', 'efetivo'],
        ['FARIAS PEREIRA DE SOUSA', 'FARIAS SOUSA', 'efetivo'],
        ['JOSÉ MARIA DE ARAUJO', 'JOSÉ MARIA', 'contrato'],
    ];

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $this->configurarInstituicao();
        $unidades = $this->criarUnidades();
        $this->criarFrota($unidades);
        $this->criarMotoristas();
        $this->montarEscalaDoMes();
    }

    private function configurarInstituicao(): void
    {
        Configuracao::atual()->update([
            'municipio' => 'Cascavel',
            'prefeitura' => 'Prefeitura Municipal de Cascavel',
            'secretaria' => 'Secretaria Municipal de Saúde',
            'setor' => 'Coordenação de Ambulâncias',
            'slogan' => 'Agora cuidando de você.',
            'cidade' => 'Cascavel',
            'uf' => 'CE',
            'telefone_1' => '8533342244',
            'telefone_2' => '8533340419',
            'responsavel_setor' => 'Coordenação de Ambulâncias',
            'cargo_responsavel' => 'Coordenador do Setor',
            'rodape_documentos' => 'PMC - SMS - COORD AMBULÂNCIAS',
        ]);
    }

    /** @return array<string, Unidade> */
    private function criarUnidades(): array
    {
        $unidades = [];
        $ordem = 0;

        foreach (self::ESTRUTURA as $sigla => $dados) {
            [$trabalho, $descanso] = array_map('intval', explode('/', $dados['regime']));

            $unidades[$sigla] = Unidade::query()->updateOrCreate(
                ['sigla' => $sigla],
                [
                    'nome' => $dados['nome'],
                    'tipo' => $dados['tipo'],
                    'cidade' => 'Cascavel',
                    'uf' => 'CE',
                    'horas_trabalho' => $trabalho,
                    'horas_descanso' => $descanso,
                    'ordem' => $ordem += 10,
                    'ativo' => true,
                ]
            );
        }

        return $unidades;
    }

    /** @param  array<string, Unidade>  $unidades */
    private function criarFrota(array $unidades): void
    {
        $modelos = [
            ['Fiat', 'Ducato'],
            ['Renault', 'Master'],
            ['Mercedes-Benz', 'Sprinter'],
            ['Peugeot', 'Boxer'],
        ];

        $i = 0;

        foreach (self::ESTRUTURA as $sigla => $dados) {
            foreach ($dados['frota'] as $identificacao => $placa) {
                [$marca, $modelo] = $modelos[$i % count($modelos)];
                $ano = 2019 + ($i % 6);

                Ambulancia::query()->updateOrCreate(
                    ['placa' => $placa],
                    [
                        'renavam' => str_pad((string) (10000000000 + $i * 137), 11, '0'),
                        'vinculo' => $i % 3 === 0 ? VinculoAmbulancia::Alugada : VinculoAmbulancia::Propria,
                        'marca' => $marca,
                        'modelo' => $modelo,
                        'ano_fabricacao' => $ano,
                        'ano_modelo' => $ano + 1,
                        'tipo' => 'Básica',
                        'identificacao' => $identificacao,
                        'unidade_id' => $unidades[$sigla]->id,
                        'ativo' => true,
                    ]
                );

                $i++;
            }
        }
    }

    private function criarMotoristas(): void
    {
        $categorias = ['D', 'AD', 'E', 'AE'];

        foreach (self::NOMES as $i => [$completo, $curto, $vinculo]) {
            Motorista::query()->updateOrCreate(
                ['nome_completo' => $completo],
                [
                    'nome_curto' => $curto,
                    // CPF ficticio com digitos previsiveis, so para a demonstracao.
                    'cpf' => str_pad((string) (10000000000 + $i * 7919), 11, '0'),
                    'data_nascimento' => Carbon::create(1970 + ($i % 30), ($i % 12) + 1, ($i % 28) + 1),
                    'vinculo' => $vinculo === 'efetivo' ? Vinculo::Efetivo : Vinculo::Contrato,
                    'vinculo_inicio' => $vinculo === 'efetivo'
                        ? Carbon::create(2008 + ($i % 12), 3, 1)
                        : now()->startOfYear(),
                    'vinculo_fim' => $vinculo === 'efetivo' ? null : now()->endOfYear()->toDateString(),
                    'cnh_categoria' => $categorias[$i % count($categorias)],
                    'cnh_validade' => now()->addMonths(8 + ($i % 40))->toDateString(),
                    'telefone_1' => '98'.str_pad((string) (100000 + $i * 1234), 7, '0'),
                    'status' => StatusMotorista::Ativo,
                ]
            );
        }
    }

    /**
     * Monta a escala do mes corrente: lota as ambulancias, define os destinos de
     * quem sobrou e gera os plantoes.
     */
    private function montarEscalaDoMes(): void
    {
        $montador = app(MontadorDeEscala::class);
        $hoje = now();

        if (\App\Models\Escala::query()->doMes($hoje->year, $hoje->month)->exists()) {
            $this->command?->warn('Já existe escala para o mês corrente; a montagem foi ignorada.');

            return;
        }

        $escala = $montador->criar($hoje->year, $hoje->month, copiarMesAnterior: false);

        $preenchidas = $montador->preencherVagasAutomaticamente($escala);
        $montador->sincronizarEfetivo($escala);

        // Dos que sobraram: dois em apoio, um de ferias, um de licenca e o
        // restante em sobreaviso.
        $pendentes = \App\Services\Escalas\AnalisadorDeEfetivo::para(
            $escala->fresh()->load('postos.lotacoes', 'lotacoes')
        )->motoristasSemDefinicao()->values();

        $unidadeSede = Unidade::query()->where('sigla', 'SEDE')->first();

        foreach ($pendentes as $i => $motorista) {
            match (true) {
                $i < 2 => $montador->definirDestino(
                    $escala, $motorista->id, TipoDestino::Apoio,
                    unidadeApoioId: $unidadeSede?->id,
                    plantoesPrevistos: 8,
                ),
                $i === 2 => $montador->definirDestino(
                    $escala, $motorista->id, TipoDestino::Ferias,
                    periodoInicio: $hoje->copy()->startOfMonth()->toDateString(),
                    periodoFim: $hoje->copy()->endOfMonth()->toDateString(),
                ),
                $i === 3 => $montador->definirDestino(
                    $escala, $motorista->id, TipoDestino::Licenca,
                    observacao: 'Licença para tratamento de saúde',
                ),
                default => $montador->definirDestino($escala, $motorista->id, TipoDestino::Reserva),
            };
        }

        $resultado = app(GeradorDeEscala::class)->gerar($escala->fresh());

        $this->command?->newLine();
        $this->command?->info("Escala de {$escala->referenciaLonga()} montada.");
        $this->command?->line("  Postos: {$escala->postos()->count()} · vagas preenchidas: {$preenchidas}");
        $this->command?->line('  '.$resultado->resumo());

        foreach ($resultado->alertas as $alerta) {
            $this->command?->warn('  '.$alerta->mensagem);
        }
    }
}
