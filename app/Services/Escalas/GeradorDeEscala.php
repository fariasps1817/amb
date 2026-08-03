<?php

namespace App\Services\Escalas;

use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPlantao;
use App\Models\EscalaPosto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gera os plantoes de uma escala mensal a partir dos postos e das lotacoes.
 *
 * ---------------------------------------------------------------------------
 * Como a rotacao funciona
 * ---------------------------------------------------------------------------
 * Cada posto (unidade + ambulancia) tem N motoristas, onde N vem do regime:
 * 24/72 = 4 motoristas, 24/48 = 3. Os motoristas ocupam as posicoes 1..N e a
 * escala simplesmente gira essa fila, um dia para cada posicao:
 *
 *   dia 01 -> posicao 1 (André)     dia 05 -> posicao 1 (André)
 *   dia 02 -> posicao 2 (Paulo)     dia 06 -> posicao 2 (Paulo)
 *   dia 03 -> posicao 3 (Ricardo)   ...
 *   dia 04 -> posicao 4 (Luiz)
 *
 * ---------------------------------------------------------------------------
 * Continuidade entre meses
 * ---------------------------------------------------------------------------
 * Reiniciar a fila no dia 1o de todo mes quebraria o descanso na virada. Por
 * isso, quando o posto esta marcado para continuar a rotacao, buscamos o ultimo
 * plantao do posto equivalente no mes anterior e deslocamos a fila:
 *
 *   offset = ((posicao_do_ultimo_plantao - 1) + dias_ate_o_novo_inicio) mod N
 *
 * Ex.: 31/08 foi a posicao 4 de um 24/72 (N = 4). O novo mes comeca em 01/09,
 * ou seja 1 dia depois: offset = ((4 - 1) + 1) mod 4 = 0 -> o dia 01/09 recebe a
 * posicao 1. O ciclo segue sem lacuna e sem plantao em dias seguidos.
 */
class GeradorDeEscala
{
    /**
     * Regera todos os plantoes da escala.
     *
     * Plantoes marcados como ajuste manual (trocas combinadas entre
     * motoristas, cobertura de falta) sao mantidos, a menos que
     * $descartarAjustesManuais seja verdadeiro.
     */
    public function gerar(Escala $escala, bool $descartarAjustesManuais = false): ResultadoGeracao
    {
        $resultado = new ResultadoGeracao;

        DB::transaction(function () use ($escala, $descartarAjustesManuais, $resultado) {
            $escala->load([
                'postos.unidade',
                'postos.ambulancia',
                'postos.lotacoes.motorista',
            ]);

            if ($escala->postos->isEmpty()) {
                $resultado->adicionarAlerta(Alerta::erro(
                    'sem_postos',
                    'Nenhum posto de escala foi definido. Cadastre as unidades e as ambulâncias do mês antes de gerar.'
                ));

                return;
            }

            foreach ($escala->postos as $posto) {
                $this->gerarPosto($escala, $posto, $descartarAjustesManuais, $resultado);
            }

            $this->recalcularPlantoesPrevistos($escala);

            $escala->update(['gerada_em' => now()]);
        });

        return $resultado;
    }

    /**
     * Gera os plantoes de um unico posto.
     */
    protected function gerarPosto(
        Escala $escala,
        EscalaPosto $posto,
        bool $descartarAjustesManuais,
        ResultadoGeracao $resultado,
    ): void {
        $vagas = $posto->vagas();
        $lotacoes = $posto->lotacoes->keyBy('posicao');

        if ($lotacoes->isEmpty()) {
            $resultado->adicionarAlerta(Alerta::erro(
                'posto_sem_motoristas',
                "O posto {$posto->descricao()} não tem nenhum motorista lotado.",
                ['posto_id' => $posto->id]
            ));

            return;
        }

        // Ajustes manuais que devem sobreviver a regeracao, indexados por data.
        $ajustes = $descartarAjustesManuais
            ? collect()
            : EscalaPlantao::query()
                ->where('escala_posto_id', $posto->id)
                ->where('ajuste_manual', true)
                ->get()
                ->keyBy(fn (EscalaPlantao $p) => $p->data->toDateString());

        $offset = $this->offsetInicial($posto, $vagas);

        // Limpa os plantoes automaticos do posto antes de regerar.
        $removidos = EscalaPlantao::query()
            ->where('escala_posto_id', $posto->id)
            ->when($ajustes->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $ajustes->pluck('id')->all()))
            ->delete();

        $resultado->plantoesRemovidos += $removidos;
        $resultado->ajustesPreservados += $ajustes->count();

        $dias = $posto->diasVigentes();
        $novos = [];
        $descobertosNoPosto = 0;
        $agora = now();

        foreach ($dias as $indice => $dia) {
            $chave = $dia->toDateString();

            // Dia com troca combinada manualmente: respeita o que foi definido.
            if ($ajustes->has($chave)) {
                continue;
            }

            $posicao = (($offset + $indice) % $vagas) + 1;
            /** @var EscalaLotacao|null $lotacao */
            $lotacao = $lotacoes->get($posicao);

            if ($lotacao === null || $lotacao->motorista_id === null) {
                $descobertosNoPosto++;
                $resultado->diasDescobertos++;

                continue;
            }

            $novos[] = [
                'escala_id' => $escala->id,
                'escala_posto_id' => $posto->id,
                'motorista_id' => $lotacao->motorista_id,
                'data' => $chave,
                'posicao' => $posicao,
                'hora_entrada' => '07:00:00',
                'hora_saida' => '07:00:00',
                'ajuste_manual' => false,
                'observacao' => null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        if ($novos !== []) {
            EscalaPlantao::query()->insert($novos);
            $resultado->plantoesCriados += count($novos);
        }

        if ($posto->vagasLivres() > 0) {
            $resultado->adicionarAlerta(Alerta::atencao(
                'posto_incompleto',
                sprintf(
                    'O posto %s opera em %s e precisa de %d motoristas, mas tem apenas %d. %d dia(s) ficaram sem cobertura.',
                    $posto->descricao(),
                    $posto->regimeNotacao(),
                    $vagas,
                    $posto->vagasOcupadas(),
                    $descobertosNoPosto,
                ),
                ['posto_id' => $posto->id]
            ));
        }
    }

    /**
     * Posicao (base zero) que o primeiro dia de vigencia do posto deve receber.
     *
     * Retorna 0 quando a rotacao deve reiniciar, ou o deslocamento calculado a
     * partir do ultimo plantao do mes anterior.
     */
    public function offsetInicial(EscalaPosto $posto, ?int $vagas = null): int
    {
        $vagas = $vagas ?: $posto->vagas();

        if (! $posto->continuar_rotacao) {
            return 0;
        }

        $ultimo = $this->ultimoPlantaoAnterior($posto);

        if ($ultimo === null) {
            return 0;
        }

        $inicio = $posto->inicioVigencia();
        $diasAte = (int) $ultimo->data->diffInDays($inicio, false);

        if ($diasAte <= 0) {
            return 0;
        }

        return (($ultimo->posicao - 1) + $diasAte) % $vagas;
    }

    /**
     * Ultimo plantao registrado antes do inicio de vigencia deste posto, no
     * posto equivalente do mes anterior.
     *
     * A equivalencia e buscada em ordem de confianca:
     *   1. mesma ambulancia   (a rotacao acompanha o veiculo)
     *   2. mesma unidade + mesmo rotulo de lotacao
     *   3. mesma unidade, quando ela tinha um unico posto
     */
    protected function ultimoPlantaoAnterior(EscalaPosto $posto): ?EscalaPlantao
    {
        $limite = $posto->inicioVigencia();

        $candidatos = EscalaPosto::query()
            ->where('escala_id', '!=', $posto->escala_id)
            ->when(
                $posto->ambulancia_id !== null,
                fn ($q) => $q->where('ambulancia_id', $posto->ambulancia_id),
                fn ($q) => $q->where('unidade_id', $posto->unidade_id)
                    ->where('rotulo', $posto->rotulo)
            )
            ->pluck('id');

        if ($candidatos->isEmpty() && $posto->ambulancia_id !== null) {
            // A ambulancia e nova no setor: tenta casar pela lotacao.
            $candidatos = EscalaPosto::query()
                ->where('escala_id', '!=', $posto->escala_id)
                ->where('unidade_id', $posto->unidade_id)
                ->where('rotulo', $posto->rotulo)
                ->pluck('id');
        }

        if ($candidatos->isEmpty()) {
            return null;
        }

        return EscalaPlantao::query()
            ->whereIn('escala_posto_id', $candidatos->all())
            ->where('data', '<', $limite->toDateString())
            ->orderByDesc('data')
            ->first();
    }

    /**
     * Atualiza a coluna de plantoes previstos de cada lotacao, que alimenta a
     * lista mensal de ocorrencias.
     *
     * Para quem esta escalado, conta os plantoes realmente gerados. Para
     * destinos administrativos, o valor e mantido como o operador definiu.
     */
    public function recalcularPlantoesPrevistos(Escala $escala): void
    {
        $contagem = EscalaPlantao::query()
            ->where('escala_id', $escala->id)
            ->selectRaw('motorista_id, count(*) as total')
            ->groupBy('motorista_id')
            ->pluck('total', 'motorista_id');

        EscalaLotacao::query()
            ->where('escala_id', $escala->id)
            ->whereNotNull('escala_posto_id')
            ->get()
            ->each(function (EscalaLotacao $lotacao) use ($contagem) {
                $total = (int) ($contagem[$lotacao->motorista_id] ?? 0);

                if ($lotacao->plantoes_previstos !== $total) {
                    $lotacao->update(['plantoes_previstos' => $total]);
                }
            });
    }

    /**
     * Pre-visualiza quem pega cada dia de um posto, sem gravar nada.
     *
     * Usado na tela de montagem para o operador conferir a fila antes de
     * confirmar a geracao.
     *
     * @return array<string, array{data: Carbon, posicao: int, motorista: ?\App\Models\Motorista}>
     */
    public function prever(EscalaPosto $posto): array
    {
        $vagas = $posto->vagas();
        $offset = $this->offsetInicial($posto, $vagas);
        $lotacoes = $posto->lotacoes->keyBy('posicao');

        $previsao = [];

        foreach ($posto->diasVigentes() as $indice => $dia) {
            $posicao = (($offset + $indice) % $vagas) + 1;

            $previsao[$dia->toDateString()] = [
                'data' => $dia,
                'posicao' => $posicao,
                'motorista' => $lotacoes->get($posicao)?->motorista,
            ];
        }

        return $previsao;
    }
}
