<?php

namespace App\Services\Documentos;

use App\Enums\TipoDestino;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPlantao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use App\Services\Escalas\GeradorDeEscala;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

            // Na ordem em que entram no mes, e nao pela posicao do ciclo: a
            // primeira linha do bloco e sempre a de quem assume o dia 1o.
            $ordem = array_flip(app(GeradorDeEscala::class)->ordemDeEntrada($posto));

            $linhas = $posto->lotacoes
                ->sortBy(fn (EscalaLotacao $lotacao) => $ordem[$lotacao->posicao] ?? PHP_INT_MAX)
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
     * Condutores que nao estao em nenhuma ambulancia no mes, agrupados pelo
     * destino: sobreaviso, apoio, ferias, licenca, atestado, cedido.
     *
     * Fecha o quadro do efetivo na propria planilha — o RH recebe a escala e a
     * relacao de quem esta fora dela no mesmo documento — e serve as unidades,
     * que precisam saber quem esta de sobreaviso para acionar em caso de falta.
     *
     * A ordem dos grupos segue TipoDestino, que ja coloca reserva e apoio
     * primeiro: sao os que continuam a disposicao do setor.
     *
     * @return Collection<int, array{
     *     tipo: TipoDestino, rotulo: string, disponivel: bool,
     *     linhas: array<int, array{motorista: Motorista, vinculo: string,
     *                              telefone: string, periodo: string,
     *                              observacao: string, plantoes: int}>
     * }>
     */
    public function foraDeEscala(): Collection
    {
        $this->escala->loadMissing(['lotacoes.motorista', 'lotacoes.unidadeApoio']);

        $porTipo = $this->escala->lotacoes
            ->filter(fn (EscalaLotacao $l) => ! $l->escalado()
                && $l->tipo_destino !== null
                && $l->motorista !== null)
            ->groupBy(fn (EscalaLotacao $l) => $l->tipo_destino->value);

        return collect(TipoDestino::cases())
            ->filter(fn (TipoDestino $tipo) => $porTipo->has($tipo->value))
            ->map(fn (TipoDestino $tipo) => [
                'tipo' => $tipo,
                'rotulo' => $tipo->rotuloLotacao(),
                'disponivel' => $tipo->disponivel(),
                'linhas' => $porTipo->get($tipo->value)
                    ->sortBy(fn (EscalaLotacao $l) => Str::transliterate(mb_strtoupper($l->motorista->nome_completo)))
                    ->values()
                    ->map(fn (EscalaLotacao $l) => [
                        'motorista' => $l->motorista,
                        'vinculo' => $l->motorista->vinculo->rotuloDocumento(),
                        'telefone' => $l->motorista->telefoneFormatado(),
                        'periodo' => $this->periodo($l),
                        'observacao' => $l->observacao ?? '',
                        'plantoes' => (int) $l->plantoes_previstos,
                    ])
                    ->all(),
            ])
            ->values();
    }

    /** Quantos condutores estao fora de escala no mes. */
    public function totalForaDeEscala(): int
    {
        return $this->foraDeEscala()->sum(fn (array $grupo) => count($grupo['linhas']));
    }

    /**
     * Periodo do afastamento, quando informado: "01 a 30/08" ou "a partir de
     * 12/08". Vazio quando o destino vale o mes inteiro.
     */
    private function periodo(EscalaLotacao $lotacao): string
    {
        if ($lotacao->periodo_inicio === null) {
            return '';
        }

        if ($lotacao->periodo_fim === null) {
            return 'a partir de '.$lotacao->periodo_inicio->format('d/m');
        }

        return $lotacao->periodo_inicio->isSameMonth($lotacao->periodo_fim)
            ? $lotacao->periodo_inicio->format('d').' a '.$lotacao->periodo_fim->format('d/m')
            : $lotacao->periodo_inicio->format('d/m').' a '.$lotacao->periodo_fim->format('d/m');
    }

    /**
     * Divide os blocos em paginas, sem quebrar um bloco ao meio.
     *
     * O documento impresso mantem cada ambulancia inteira na mesma pagina: o
     * leitor precisa ver as 3 ou 4 posicoes do ciclo juntas para entender o
     * revezamento.
     *
     * @param  int  $linhasPorPagina  Capacidade util de linhas em cada folha.
     * @param  int  $linhasExtrasPorBloco  Linhas que o layout gasta por bloco
     *                                     alem das dos motoristas — o layout
     *                                     agrupado usa uma para a faixa de
     *                                     identificacao da ambulancia.
     * @return array<int, array{blocos: array<int, array>, primeiro_numero: int}>
     */
    public function paginas(int $linhasPorPagina = 42, int $linhasExtrasPorBloco = 0): array
    {
        $paginas = [];
        $atual = [];
        $ocupadas = 0;
        $numero = 1;
        $primeiroNumero = 1;

        foreach ($this->blocos as $bloco) {
            $altura = count($bloco['linhas'])
                + $linhasExtrasPorBloco
                + ($bloco['vagas_livres'] > 0 ? 1 : 0);

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
