<?php

namespace App\Services\Documentos;

use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPlantao;
use App\Models\EscalaPosto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monta os dados da planilha mensal de plantoes — o documento distribuido as
 * unidades, em que cada linha e um motorista e cada coluna um dia do mes.
 *
 * A montagem e feita uma vez e reaproveitada pela tela e pelo PDF, para os dois
 * nunca divergirem.
 */
final class DadosDaPlanilha
{
    /** @var Collection<int, array> */
    public Collection $blocos;

    /** @var array<int, Carbon> */
    public array $dias;

    private function __construct(public Escala $escala)
    {
        $this->dias = $escala->dias();
        $this->blocos = $this->montarBlocos();
    }

    public static function para(Escala $escala): self
    {
        $escala->loadMissing([
            'postos.unidade',
            'postos.ambulancia',
            'postos.lotacoes.motorista',
            'postos.plantoes',
        ]);

        return new self($escala);
    }

    /**
     * Um bloco por posto (ambulancia), com as linhas de cada motorista e o mapa
     * de dias em que ele esta de plantao.
     *
     * @return Collection<int, array{
     *     posto: EscalaPosto,
     *     placa: string,
     *     lotacao: string,
     *     regime: string,
     *     linhas: array<int, array{motorista: mixed, posicao: int, dias: array<string, EscalaPlantao>}>,
     *     vagas_livres: int
     * }>
     */
    private function montarBlocos(): Collection
    {
        return $this->escala->postos->map(function (EscalaPosto $posto) {
            // Plantoes do posto agrupados por motorista e indexados por data.
            $porMotorista = $posto->plantoes
                ->groupBy('motorista_id')
                ->map(fn (Collection $plantoes) => $plantoes->keyBy(
                    fn (EscalaPlantao $p) => $p->data->toDateString()
                ));

            $linhas = $posto->lotacoes
                ->sortBy('posicao')
                ->map(fn (EscalaLotacao $lotacao) => [
                    'motorista' => $lotacao->motorista,
                    'posicao' => (int) $lotacao->posicao,
                    'dias' => $porMotorista->get($lotacao->motorista_id, collect())->all(),
                ])
                ->values()
                ->all();

            return [
                'posto' => $posto,
                'placa' => $posto->rotuloPlaca(),
                'lotacao' => $posto->rotuloLotacao(),
                'regime' => $posto->regimeNotacao(),
                'unidade' => $posto->unidade?->nome ?? '',
                'linhas' => $linhas,
                'vagas_livres' => $posto->vagasLivres(),
            ];
        });
    }

    /** Total de linhas de motorista impressas, para numeracao sequencial. */
    public function totalLinhas(): int
    {
        return $this->blocos->sum(fn (array $bloco) => count($bloco['linhas']));
    }

    /**
     * Divide os blocos em paginas, sem quebrar um bloco ao meio.
     *
     * O documento impresso mantem cada ambulancia inteira na mesma pagina: o
     * leitor precisa ver as 3 ou 4 posicoes do ciclo juntas para entender o
     * revezamento.
     *
     * @param  int  $linhasPorPagina  Capacidade util de linhas em cada folha.
     * @return array<int, array{blocos: array<int, array>, primeiro_numero: int}>
     */
    public function paginas(int $linhasPorPagina = 42): array
    {
        $paginas = [];
        $atual = [];
        $ocupadas = 0;
        $numero = 1;
        $primeiroNumero = 1;

        foreach ($this->blocos as $bloco) {
            $altura = count($bloco['linhas']) + ($bloco['vagas_livres'] > 0 ? 1 : 0);

            if ($atual !== [] && $ocupadas + $altura > $linhasPorPagina) {
                $paginas[] = ['blocos' => $atual, 'primeiro_numero' => $primeiroNumero];
                $primeiroNumero = $numero;
                $atual = [];
                $ocupadas = 0;
            }

            $atual[] = $bloco;
            $ocupadas += $altura;
            $numero += count($bloco['linhas']);
        }

        if ($atual !== []) {
            $paginas[] = ['blocos' => $atual, 'primeiro_numero' => $primeiroNumero];
        }

        return $paginas;
    }
}
