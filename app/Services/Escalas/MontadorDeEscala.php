<?php

namespace App\Services\Escalas;

use App\Enums\StatusEscala;
use App\Enums\StatusMotorista;
use App\Enums\TipoDestino;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPlantao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use App\Models\Unidade;
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

        $this->garantirMesSemOficial($ano, $mes);

        $anteriorRef = Carbon::create($ano, $mes, 1)->subMonth();
        $semDecisao = Escala::propostasSemDecisao($anteriorRef->year, $anteriorRef->month);

        // Copiar de um mes ainda indefinido seria escolher uma das propostas
        // por conta propria -- e e dela que a rotacao continuaria.
        if ($copiarMesAnterior && $semDecisao->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                '%s tem %d propostas e nenhuma foi publicada. Publique a escolhida antes de copiar a estrutura, ou duplique uma das propostas.',
                ucfirst($anteriorRef->translatedFormat('F \d\e Y')),
                $semDecisao->count(),
            ));
        }

        return DB::transaction(function () use ($ano, $mes, $copiarMesAnterior) {
            $escala = Escala::query()->create([
                'ano' => $ano,
                'mes' => $mes,
                'status' => StatusEscala::Rascunho,
                'nome' => $this->nomearNovaProposta($ano, $mes),
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

    // -----------------------------------------------------------------
    // Propostas alternativas do mes
    // -----------------------------------------------------------------

    /**
     * Copia uma proposta inteira -- ambulancias, ordem, equipes, destinos,
     * ocorrencias e os plantoes ja gerados, com as trocas manuais -- para o
     * coordenador montar a alternativa mudando so o que difere.
     */
    public function duplicar(Escala $origem, ?string $nome = null): Escala
    {
        if ($origem->oficial()) {
            throw new RuntimeException('A escala de '.$origem->referenciaLonga().' já está publicada. Para ajustá-la, use "Reabrir para ajustes".');
        }

        $this->garantirMesSemOficial($origem->ano, $origem->mes);

        return DB::transaction(function () use ($origem, $nome) {
            $origem->load(['postos', 'lotacoes', 'plantoes']);

            $nova = Escala::query()->create([
                'ano' => $origem->ano,
                'mes' => $origem->mes,
                'status' => StatusEscala::Rascunho,
                'nome' => filled($nome) ? trim($nome) : $this->nomearNovaProposta($origem->ano, $origem->mes),
                'origem_id' => $origem->id,
                'observacao' => $origem->observacao,
                'gerada_em' => $origem->gerada_em,
                'criada_por' => auth()->id(),
            ]);

            $postos = [];

            foreach ($origem->postos as $posto) {
                $postos[$posto->id] = EscalaPosto::query()->create(
                    ['escala_id' => $nova->id] + $posto->only([
                        'unidade_id', 'ambulancia_id', 'horas_trabalho', 'horas_descanso', 'rotulo',
                        'data_inicio', 'data_fim', 'continuar_rotacao', 'ordem', 'observacao',
                    ])
                )->id;
            }

            foreach ($origem->lotacoes as $lotacao) {
                EscalaLotacao::query()->create(
                    [
                        'escala_id' => $nova->id,
                        'escala_posto_id' => $lotacao->escala_posto_id ? $postos[$lotacao->escala_posto_id] : null,
                    ] + $lotacao->only([
                        'motorista_id', 'posicao', 'tipo_destino', 'unidade_apoio_id', 'periodo_inicio',
                        'periodo_fim', 'plantoes_previstos', 'plantoes_ajustados', 'observacao',
                    ])
                );
            }

            $agora = now();

            foreach ($origem->plantoes->chunk(500) as $lote) {
                EscalaPlantao::query()->insert($lote->map(fn (EscalaPlantao $p) => [
                    'escala_id' => $nova->id,
                    'escala_posto_id' => $postos[$p->escala_posto_id],
                    'motorista_id' => $p->motorista_id,
                    'data' => $p->data->toDateString(),
                    'posicao' => $p->posicao,
                    'hora_entrada' => $p->getRawOriginal('hora_entrada'),
                    'hora_saida' => $p->getRawOriginal('hora_saida'),
                    'ajuste_manual' => $p->ajuste_manual,
                    'observacao' => $p->observacao,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])->all());
            }

            return $nova;
        });
    }

    /**
     * Publica a proposta escolhida. As outras do mes saem do caminho na mesma
     * transacao: excluidas, ou guardadas como descartadas para consulta.
     *
     * @return int Quantas outras propostas foram excluidas ou descartadas.
     */
    public function publicar(
        Escala $escala,
        bool $excluirOutras = true,
        ?string $aprovadaPor = null,
        ?Carbon $aprovadaEm = null,
    ): int {
        return DB::transaction(function () use ($escala, $excluirOutras, $aprovadaPor, $aprovadaEm) {
            // Trava as escalas do mes ate o fim da transacao: duas publicacoes
            // simultaneas esperam uma pela outra em vez de se atropelarem.
            $outras = Escala::query()
                ->doMes($escala->ano, $escala->mes)
                ->whereKeyNot($escala->id)
                ->lockForUpdate()
                ->get();

            if ($outras->contains(fn (Escala $e) => $e->oficial())) {
                throw new RuntimeException('Já existe uma escala publicada para '.$escala->referenciaLonga().'.');
            }

            $propostas = $outras->filter(fn (Escala $e) => $e->ehRascunho());

            foreach ($propostas as $proposta) {
                $excluirOutras
                    ? $proposta->delete()
                    : $proposta->update(['status' => StatusEscala::Descartada]);
            }

            $escala->update([
                'status' => StatusEscala::Publicada,
                'publicada_em' => now(),
                'publicada_por' => auth()->id(),
                'aprovada_por' => filled($aprovadaPor) ? trim($aprovadaPor) : null,
                'aprovada_em' => $aprovadaEm?->toDateString(),
            ]);

            return $propostas->count();
        });
    }

    /**
     * Devolve uma proposta descartada para rascunho -- enquanto o mes ainda
     * nao tem escala publicada.
     */
    public function restaurar(Escala $escala): void
    {
        $this->garantirMesSemOficial($escala->ano, $escala->mes);

        $escala->update(['status' => StatusEscala::Rascunho]);
    }

    /**
     * Mes com escala publicada nao recebe propostas novas: o ajuste se faz
     * reabrindo a publicada, que e a que as unidades ja receberam.
     */
    private function garantirMesSemOficial(int $ano, int $mes): void
    {
        $oficial = Escala::query()->doMes($ano, $mes)->get()->first(fn (Escala $e) => $e->oficial());

        if ($oficial !== null) {
            throw new RuntimeException(
                'A escala de '.$oficial->referenciaLonga().' já está '.mb_strtolower($oficial->status->rotulo())
                .'. Para ajustá-la, abra a escala e use "Reabrir para ajustes".'
            );
        }
    }

    /**
     * Nome da proxima proposta do mes: vazio quando sera a unica e "Proposta B",
     * "C"... quando ja ha outras -- e a primeira, ainda sem nome, vira a "A".
     */
    private function nomearNovaProposta(int $ano, int $mes): ?string
    {
        $existentes = Escala::query()->doMes($ano, $mes)->orderBy('id')->get();

        if ($existentes->isEmpty()) {
            return null;
        }

        foreach ($existentes->whereNull('nome') as $semNome) {
            $semNome->update(['nome' => $this->primeiraLetraLivre($existentes->pluck('nome')->all())]);
            $existentes = Escala::query()->doMes($ano, $mes)->orderBy('id')->get();
        }

        return $this->primeiraLetraLivre($existentes->pluck('nome')->all());
    }

    /** @param  array<int, string|null>  $usados */
    private function primeiraLetraLivre(array $usados): string
    {
        foreach (range('A', 'Z') as $letra) {
            if (! in_array("Proposta {$letra}", $usados, true)) {
                return "Proposta {$letra}";
            }
        }

        return 'Proposta '.(count($usados) + 1);
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
        $unidade = Unidade::query()->findOrFail($unidadeId);
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

        $existentes = $escala->postos()->get(['id', 'unidade_id']);

        $posto = EscalaPosto::query()->create([
            'escala_id' => $escala->id,
            'unidade_id' => $unidade->id,
            'ambulancia_id' => $ambulanciaId,
            'horas_trabalho' => $unidade->horas_trabalho,
            'horas_descanso' => $unidade->horas_descanso,
            'rotulo' => $rotulo ?: ($ambulancia?->identificacao ?: $unidade->sigla),
            'continuar_rotacao' => true,
            'ordem' => 0,
        ]);

        // Entra logo depois da ultima ambulancia da mesma unidade: a SEDE 4
        // vai para depois da SEDE 3, e nao para o fim da planilha. Unidade
        // ainda sem ambulancia no mes vai para o fim.
        $ids = $existentes->pluck('id')->all();
        $ultimaDaUnidade = $existentes->where('unidade_id', $unidade->id)->last();
        $lugar = $ultimaDaUnidade ? array_search($ultimaDaUnidade->id, $ids, true) + 1 : count($ids);

        array_splice($ids, $lugar, 0, [$posto->id]);
        $this->renumerarPostos($ids);

        return $posto->refresh();
    }

    /**
     * Sobe (-1) ou desce (+1) o posto uma casa na sequencia do mes. E a ordem
     * dos blocos na montagem, na planilha impressa e na lista de quem esta de
     * plantao, e e copiada quando o mes seguinte repete a estrutura.
     */
    public function moverPosto(EscalaPosto $posto, int $direcao): void
    {
        $ids = EscalaPosto::query()
            ->where('escala_id', $posto->escala_id)
            ->orderBy('ordem')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $atual = array_search($posto->id, $ids, true);
        $destino = $atual + ($direcao < 0 ? -1 : 1);

        if ($atual === false || ! isset($ids[$destino])) {
            return;
        }

        [$ids[$atual], $ids[$destino]] = [$ids[$destino], $ids[$atual]];

        $this->renumerarPostos($ids);
    }

    /**
     * Grava a sequencia de 10 em 10. Renumerar tudo, em vez de trocar dois
     * valores, desfaz empates antigos -- postos com a mesma ordem ficariam
     * sem posicao definida entre si.
     *
     * @param  list<int>  $ids
     */
    private function renumerarPostos(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $indice => $id) {
                EscalaPosto::query()->whereKey($id)->update(['ordem' => ($indice + 1) * 10]);
            }
        });
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
