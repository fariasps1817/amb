<?php

namespace App\Services\Escalas;

use App\Enums\StatusEscala;
use App\Enums\StatusMotorista;
use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Criacao e montagem da estrutura de uma escala mensal.
 *
 * Na pratica o coordenador nao monta o mes do zero: ele repete a estrutura do
 * mes anterior (mesmas unidades, mesmas ambulancias, mesmas equipes) e ajusta o
 * que mudou. Por isso a copia do mes anterior e o caminho principal e a
 * montagem a partir da frota e o caminho para o primeiro mes.
 */
class MontadorDeEscala
{
    /**
     * Cria a escala do mes, replicando a estrutura do mes anterior quando
     * existir.
     */
    public function criar(int $ano, int $mes, bool $copiarMesAnterior = true): Escala
    {
        if ($mes < 1 || $mes > 12) {
            throw new RuntimeException('Mês inválido.');
        }

        if (Escala::query()->doMes($ano, $mes)->exists()) {
            throw new RuntimeException(
                'Já existe uma escala para '.Carbon::create($ano, $mes, 1)->translatedFormat('F \d\e Y').'.'
            );
        }

        return DB::transaction(function () use ($ano, $mes, $copiarMesAnterior) {
            $escala = Escala::query()->create([
                'ano' => $ano,
                'mes' => $mes,
                'status' => StatusEscala::Rascunho,
                'criada_por' => auth()->id(),
            ]);

            $anterior = $escala->anterior();

            if ($copiarMesAnterior && $anterior !== null) {
                $this->copiarEstrutura($anterior, $escala);
            } else {
                $this->montarDaFrota($escala);
            }

            return $escala;
        });
    }

    /**
     * Replica postos e equipes de uma escala para outra.
     *
     * Motoristas que ficaram inativos, com contrato encerrado ou com CNH
     * vencida no novo periodo nao sao copiados: a vaga fica aberta para o
     * coordenador preencher, e o alerta de posto incompleto aparece na tela.
     *
     * Destinos temporarios (ferias, licenca, atestado) tambem nao sao copiados,
     * pois valem para o mes de origem. Reserva e apoio sao mantidos.
     */
    public function copiarEstrutura(Escala $origem, Escala $destino): void
    {
        $origem->load(['postos.lotacoes.motorista', 'lotacoes.motorista']);

        $inicio = $destino->primeiroDia();
        $fim = $destino->ultimoDia();

        foreach ($origem->postos as $postoOrigem) {
            $posto = EscalaPosto::query()->create([
                'escala_id' => $destino->id,
                'unidade_id' => $postoOrigem->unidade_id,
                'ambulancia_id' => $postoOrigem->ambulancia_id,
                'horas_trabalho' => $postoOrigem->horas_trabalho,
                'horas_descanso' => $postoOrigem->horas_descanso,
                'rotulo' => $postoOrigem->rotulo,
                'continuar_rotacao' => true,
                'ordem' => $postoOrigem->ordem,
            ]);

            foreach ($postoOrigem->lotacoes as $lotacaoOrigem) {
                $motorista = $lotacaoOrigem->motorista;

                if ($motorista === null) {
                    continue;
                }

                // Deixa a vaga aberta quando o motorista nao pode assumir.
                if ($motorista->impedimentosNoPeriodo($inicio, $fim) !== []) {
                    continue;
                }

                EscalaLotacao::query()->create([
                    'escala_id' => $destino->id,
                    'motorista_id' => $motorista->id,
                    'escala_posto_id' => $posto->id,
                    'posicao' => $lotacaoOrigem->posicao,
                ]);
            }
        }

        // Reserva e apoio seguem para o mes novo; afastamentos nao.
        foreach ($origem->lotacoes as $lotacaoOrigem) {
            if ($lotacaoOrigem->escalado() || $lotacaoOrigem->tipo_destino === null) {
                continue;
            }

            if (! $lotacaoOrigem->tipo_destino->disponivel()) {
                continue;
            }

            $motorista = $lotacaoOrigem->motorista;

            if ($motorista === null || ! $motorista->ativo()) {
                continue;
            }

            if ($motorista->impedimentosNoPeriodo($inicio, $fim) !== []) {
                continue;
            }

            EscalaLotacao::query()->firstOrCreate(
                ['escala_id' => $destino->id, 'motorista_id' => $motorista->id],
                [
                    'tipo_destino' => $lotacaoOrigem->tipo_destino,
                    'unidade_apoio_id' => $lotacaoOrigem->unidade_apoio_id,
                    'plantoes_previstos' => $lotacaoOrigem->tipo_destino->plantoesPadrao(),
                ]
            );
        }
    }

    /**
     * Monta os postos a partir da frota ativa, usada quando nao existe mes
     * anterior. Cada ambulancia ativa com unidade definida gera um posto, com o
     * regime herdado da unidade.
     */
    public function montarDaFrota(Escala $escala): int
    {
        // A ordem dos postos define a sequencia dos blocos na planilha impressa:
        // primeiro a ordem definida no cadastro da unidade, depois o nome dela e
        // por fim a identificacao do veiculo (SEDE 1, SEDE 2, SEDE 3).
        //
        // A chave e montada como texto unico porque Collection::sortBy() com
        // array espera pares [campo, direcao] — passar varias closures nao
        // produz a ordenacao esperada.
        $ambulancias = Ambulancia::query()
            ->ativas()
            ->whereNotNull('unidade_id')
            ->with('unidade')
            ->get()
            ->sortBy(fn (Ambulancia $a) => sprintf(
                '%06d|%s|%s',
                $a->unidade?->ordem ?? 0,
                $a->unidade?->nome ?? '',
                $a->identificacao ?: $a->placa,
            ));

        $ordem = 0;

        foreach ($ambulancias as $ambulancia) {
            $unidade = $ambulancia->unidade;

            if ($unidade === null || ! $unidade->ativo) {
                continue;
            }

            EscalaPosto::query()->create([
                'escala_id' => $escala->id,
                'unidade_id' => $unidade->id,
                'ambulancia_id' => $ambulancia->id,
                'horas_trabalho' => $unidade->horas_trabalho,
                'horas_descanso' => $unidade->horas_descanso,
                'rotulo' => $ambulancia->identificacao ?: $unidade->sigla,
                'continuar_rotacao' => true,
                'ordem' => $ordem += 10,
            ]);
        }

        return $ordem > 0 ? intdiv($ordem, 10) : 0;
    }

    /**
     * Adiciona um posto a escala, herdando o regime da unidade.
     */
    public function adicionarPosto(Escala $escala, int $unidadeId, ?int $ambulanciaId, ?string $rotulo = null): EscalaPosto
    {
        $unidade = \App\Models\Unidade::query()->findOrFail($unidadeId);
        $ambulancia = $ambulanciaId ? Ambulancia::query()->find($ambulanciaId) : null;

        if ($ambulanciaId !== null) {
            $jaUsada = EscalaPosto::query()
                ->where('escala_id', $escala->id)
                ->where('ambulancia_id', $ambulanciaId)
                ->exists();

            if ($jaUsada) {
                throw new RuntimeException(
                    'A ambulância '.($ambulancia?->placaFormatada() ?? '').' já está em outro posto desta escala.'
                );
            }
        }

        $proximaOrdem = (int) EscalaPosto::query()->where('escala_id', $escala->id)->max('ordem') + 10;

        return EscalaPosto::query()->create([
            'escala_id' => $escala->id,
            'unidade_id' => $unidade->id,
            'ambulancia_id' => $ambulanciaId,
            'horas_trabalho' => $unidade->horas_trabalho,
            'horas_descanso' => $unidade->horas_descanso,
            'rotulo' => $rotulo ?: ($ambulancia?->identificacao ?: $unidade->sigla),
            'continuar_rotacao' => true,
            'ordem' => $proximaOrdem,
        ]);
    }

    /**
     * Lota um motorista em uma posicao do posto.
     *
     * Garante que o motorista tenha um unico destino no mes: se ele ja estava em
     * outro posto ou em um destino administrativo, a lotacao e movida.
     */
    public function lotarMotorista(EscalaPosto $posto, int $motoristaId, int $posicao): EscalaLotacao
    {
        $vagas = $posto->vagas();

        if ($posicao < 1 || $posicao > $vagas) {
            throw new RuntimeException("A posição deve estar entre 1 e {$vagas} para o regime {$posto->regimeNotacao()}.");
        }

        return DB::transaction(function () use ($posto, $motoristaId, $posicao) {
            $ocupante = EscalaLotacao::query()
                ->where('escala_posto_id', $posto->id)
                ->where('posicao', $posicao)
                ->first();

            if ($ocupante !== null && $ocupante->motorista_id !== $motoristaId) {
                // Libera a posicao: o antigo ocupante volta a ficar sem destino.
                $ocupante->update([
                    'escala_posto_id' => null,
                    'posicao' => null,
                    'plantoes_previstos' => 0,
                ]);
            }

            $lotacao = EscalaLotacao::query()->firstOrNew([
                'escala_id' => $posto->escala_id,
                'motorista_id' => $motoristaId,
            ]);

            $lotacao->fill([
                'escala_posto_id' => $posto->id,
                'posicao' => $posicao,
                'tipo_destino' => null,
                'unidade_apoio_id' => null,
                'periodo_inicio' => null,
                'periodo_fim' => null,
            ]);

            $lotacao->save();

            return $lotacao;
        });
    }

    /**
     * Define um destino administrativo (reserva, ferias, licenca...) para o
     * motorista, retirando-o de qualquer posto.
     */
    public function definirDestino(
        Escala $escala,
        int $motoristaId,
        TipoDestino $tipo,
        ?int $unidadeApoioId = null,
        ?string $observacao = null,
        ?string $periodoInicio = null,
        ?string $periodoFim = null,
        ?int $plantoesPrevistos = null,
    ): EscalaLotacao {
        $lotacao = EscalaLotacao::query()->firstOrNew([
            'escala_id' => $escala->id,
            'motorista_id' => $motoristaId,
        ]);

        $lotacao->fill([
            'escala_posto_id' => null,
            'posicao' => null,
            'tipo_destino' => $tipo,
            'unidade_apoio_id' => $tipo === TipoDestino::Apoio ? $unidadeApoioId : null,
            'observacao' => $observacao,
            'periodo_inicio' => $periodoInicio,
            'periodo_fim' => $periodoFim,
            'plantoes_previstos' => $plantoesPrevistos ?? $tipo->plantoesPadrao(),
        ]);

        $lotacao->save();

        // Remove os plantoes que ele tinha antes de sair do posto.
        $escala->plantoes()->where('motorista_id', $motoristaId)->delete();

        return $lotacao;
    }

    /**
     * Atalho para o fechamento do mes: marca todos os motoristas ativos que
     * ficaram sem definicao como reserva/sobreaviso.
     *
     * @return int Quantidade de motoristas ajustados.
     */
    public function definirRestantesComoReserva(Escala $escala, TipoDestino $tipo = TipoDestino::Reserva): int
    {
        $pendentes = AnalisadorDeEfetivo::para($escala)->motoristasSemDefinicao();

        foreach ($pendentes as $motorista) {
            EscalaLotacao::query()->updateOrCreate(
                ['escala_id' => $escala->id, 'motorista_id' => $motorista->id],
                [
                    'escala_posto_id' => null,
                    'posicao' => null,
                    'tipo_destino' => $tipo,
                    'plantoes_previstos' => $tipo->plantoesPadrao(),
                ]
            );
        }

        return $pendentes->count();
    }

    /**
     * Preenche automaticamente os postos incompletos com os motoristas ainda
     * disponiveis, na ordem alfabetica. Serve de ponto de partida: o
     * coordenador reordena depois.
     *
     * @return int Quantidade de vagas preenchidas.
     */
    public function preencherVagasAutomaticamente(Escala $escala): int
    {
        $escala->load(['postos.lotacoes']);

        $disponiveis = AnalisadorDeEfetivo::para($escala)
            ->motoristasDisponiveisParaLotar()
            ->values();

        $indice = 0;
        $preenchidas = 0;

        foreach ($escala->postos as $posto) {
            $ocupadas = $posto->lotacoes->pluck('posicao')->filter()->all();

            for ($posicao = 1; $posicao <= $posto->vagas(); $posicao++) {
                if (in_array($posicao, $ocupadas, true)) {
                    continue;
                }

                /** @var Motorista|null $motorista */
                $motorista = $disponiveis[$indice] ?? null;

                if ($motorista === null) {
                    return $preenchidas;
                }

                $indice++;

                $this->lotarMotorista($posto, $motorista->id, $posicao);
                $preenchidas++;
            }
        }

        return $preenchidas;
    }

    /**
     * Garante que todo motorista ativo tenha uma linha na folha de lotacao do
     * mes, mesmo que ainda sem definicao. Facilita a tela de destinos.
     */
    public function sincronizarEfetivo(Escala $escala): int
    {
        $existentes = $escala->lotacoes()->pluck('motorista_id')->all();

        $novos = Motorista::query()
            ->where('status', StatusMotorista::Ativo)
            ->whereNotIn('id', $existentes ?: [0])
            ->pluck('id');

        $agora = now();

        if ($novos->isNotEmpty()) {
            EscalaLotacao::query()->insert(
                $novos->map(fn ($id) => [
                    'escala_id' => $escala->id,
                    'motorista_id' => $id,
                    'plantoes_previstos' => 0,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])->all()
            );
        }

        return $novos->count();
    }
}
