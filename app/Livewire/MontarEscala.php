<?php

namespace App\Livewire;

use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\EscalaPosto;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\AnalisadorDeEfetivo;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use RuntimeException;

/**
 * Montagem da escala mensal: postos (unidade + ambulancia) e a equipe de cada um.
 *
 * A tela mostra, para cada ambulancia, as posicoes do ciclo que o regime exige
 * (4 posicoes em 24/72, 3 em 24/48) e permite encaixar um motorista em cada uma.
 * A posicao 1 pega o primeiro dia de vigencia do posto, a 2 o dia seguinte, e
 * assim por diante.
 */
class MontarEscala extends Component
{
    public Escala $escala;

    /** Posto que esta com o painel de lotacao aberto. */
    public ?int $postoAberto = null;

    /** Filtro do seletor de motoristas. */
    public string $buscaMotorista = '';

    /** Formulario de novo posto. */
    public ?int $novaUnidadeId = null;

    public ?int $novaAmbulanciaId = null;

    public bool $mostrarNovoPosto = false;

    public function mount(Escala $escala): void
    {
        abort_unless($escala->editavel(), 403);

        $this->escala = $escala;
    }

    // -----------------------------------------------------------------
    // Dados da tela
    // -----------------------------------------------------------------

    #[Computed]
    public function postos(): Collection
    {
        return $this->escala
            ->postos()
            ->with(['unidade', 'ambulancia', 'lotacoes.motorista'])
            ->get();
    }

    #[Computed]
    public function analisador(): AnalisadorDeEfetivo
    {
        return AnalisadorDeEfetivo::para($this->escala->fresh()->load('postos.lotacoes', 'lotacoes'));
    }

    #[Computed]
    public function resumo(): array
    {
        return $this->analisador()->resumo();
    }

    #[Computed]
    public function alertas(): array
    {
        return $this->analisador()->alertas();
    }

    /** Motoristas que ainda podem ser lotados, aplicando o filtro de busca. */
    #[Computed]
    public function candidatos(): Collection
    {
        $disponiveis = $this->analisador()->motoristasDisponiveisParaLotar();

        $termo = trim($this->buscaMotorista);

        if ($termo === '') {
            return $disponiveis;
        }

        $termo = mb_strtolower($termo);

        return $disponiveis->filter(fn (Motorista $m) => str_contains(mb_strtolower($m->nome_completo), $termo)
            || str_contains(mb_strtolower($m->nome_curto), $termo)
        )->values();
    }

    #[Computed]
    public function unidades(): Collection
    {
        return Unidade::query()->ativas()->ordenada()->get();
    }

    /** Ambulancias ativas que ainda nao estao em nenhum posto deste mes. */
    #[Computed]
    public function ambulanciasLivres(): Collection
    {
        $ocupadas = $this->postos()->pluck('ambulancia_id')->filter()->all();

        return Ambulancia::query()
            ->ativas()
            ->whereNotIn('id', $ocupadas ?: [0])
            ->with('unidade')
            ->orderBy('identificacao')
            ->orderBy('placa')
            ->get();
    }

    // -----------------------------------------------------------------
    // Postos
    // -----------------------------------------------------------------

    public function abrirNovoPosto(): void
    {
        $this->mostrarNovoPosto = true;
        $this->novaUnidadeId = null;
        $this->novaAmbulanciaId = null;
    }

    public function cancelarNovoPosto(): void
    {
        $this->mostrarNovoPosto = false;
        $this->novaUnidadeId = null;
        $this->novaAmbulanciaId = null;
    }

    /**
     * Ao escolher a ambulancia, sugere a unidade de lotacao padrao dela.
     */
    public function updatedNovaAmbulanciaId($valor): void
    {
        if (blank($valor) || filled($this->novaUnidadeId)) {
            return;
        }

        $this->novaUnidadeId = Ambulancia::query()->find($valor)?->unidade_id;
    }

    public function adicionarPosto(MontadorDeEscala $montador): void
    {
        $this->validate([
            'novaUnidadeId' => ['required', 'exists:unidades,id'],
            'novaAmbulanciaId' => ['required', 'exists:ambulancias,id'],
        ], attributes: [
            'novaUnidadeId' => 'unidade',
            'novaAmbulanciaId' => 'ambulância',
        ]);

        try {
            $posto = $montador->adicionarPosto($this->escala, $this->novaUnidadeId, $this->novaAmbulanciaId);
        } catch (RuntimeException $e) {
            $this->addError('novaAmbulanciaId', $e->getMessage());

            return;
        }

        $this->cancelarNovoPosto();
        $this->postoAberto = $posto->id;
        $this->limparCache();

        $this->dispatch('aviso', tipo: 'sucesso', texto: "Posto {$posto->descricao()} adicionado.");
    }

    public function removerPosto(int $postoId): void
    {
        $posto = $this->posto($postoId);
        $descricao = $posto->descricao();

        // Apagar o posto leva as lotacoes e os plantoes por cascata; os
        // motoristas voltam para a lista de disponiveis.
        $posto->delete();

        if ($this->postoAberto === $postoId) {
            $this->postoAberto = null;
        }

        $this->limparCache();
        $this->dispatch('aviso', tipo: 'atencao', texto: "Posto {$descricao} removido da escala.");
    }

    /**
     * Troca a unidade do posto, adotando o regime da nova lotacao.
     *
     * Se o novo regime exigir menos motoristas, as posicoes que passaram do
     * limite sao liberadas — do contrario ficariam ocupadas sem gerar plantao.
     */
    public function alterarUnidadeDoPosto(int $postoId, ?int $unidadeId): void
    {
        if (blank($unidadeId)) {
            return;
        }

        $posto = $this->posto($postoId);
        $unidade = Unidade::query()->findOrFail($unidadeId);

        $posto->update([
            'unidade_id' => $unidade->id,
            'rotulo' => $posto->ambulancia?->identificacao ?: $unidade->sigla,
            'horas_trabalho' => $unidade->horas_trabalho,
            'horas_descanso' => $unidade->horas_descanso,
        ]);

        $vagas = $unidade->motoristasPorAmbulancia();

        $liberadas = EscalaLotacao::query()
            ->where('escala_posto_id', $posto->id)
            ->where('posicao', '>', $vagas)
            ->update(['escala_posto_id' => null, 'posicao' => null, 'plantoes_previstos' => 0]);

        $this->limparCache();

        $texto = "Posto remanejado para {$unidade->sigla} em regime {$unidade->regimeNotacao()} ({$vagas} motoristas).";

        if ($liberadas > 0) {
            $texto .= " {$liberadas} motorista(s) foram liberados por excederem o novo ciclo.";
        }

        $this->dispatch('aviso', tipo: 'atencao', texto: $texto);
    }

    /**
     * Define o periodo de operacao do posto dentro do mes.
     *
     * Permite a escala comecar depois do dia 1o — caso de ambulancia entregue no
     * meio do mes — ou encerrar antes do ultimo dia, quando o veiculo sai de
     * operacao. Vazio significa o mes inteiro.
     */
    public function alterarVigencia(int $postoId, string $campo, ?string $valor): void
    {
        if (! in_array($campo, ['data_inicio', 'data_fim'], true)) {
            return;
        }

        $posto = $this->posto($postoId);
        $data = blank($valor) ? null : Carbon::parse($valor)->startOfDay();

        // A data precisa cair dentro do mes da escala, senao o posto ficaria sem
        // nenhum dia de plantao sem que o operador entendesse o motivo.
        if ($data !== null && ! $data->betweenIncluded($this->escala->primeiroDia(), $this->escala->ultimoDia())) {
            $this->dispatch(
                'aviso',
                tipo: 'erro',
                texto: 'A data precisa estar dentro de '.$this->escala->referenciaLonga().'.'
            );

            return;
        }

        $inicio = $campo === 'data_inicio' ? $data : $posto->data_inicio;
        $fim = $campo === 'data_fim' ? $data : $posto->data_fim;

        if ($inicio !== null && $fim !== null && $fim->lessThan($inicio)) {
            $this->dispatch('aviso', tipo: 'erro', texto: 'O término não pode ser anterior ao início.');

            return;
        }

        $posto->update([$campo => $data?->toDateString()]);

        $this->limparCache();

        $this->dispatch(
            'aviso',
            tipo: 'sucesso',
            texto: $data === null
                ? 'O posto volta a operar o mês inteiro.'
                : ($campo === 'data_inicio'
                    ? 'Os plantões deste posto começam em '.$data->format('d/m').'.'
                    : 'Os plantões deste posto encerram em '.$data->format('d/m').'.'),
        );
    }

    public function alternarRotacao(int $postoId): void
    {
        $posto = $this->posto($postoId);
        $posto->update(['continuar_rotacao' => ! $posto->continuar_rotacao]);

        $this->limparCache();

        $this->dispatch(
            'aviso',
            tipo: 'sucesso',
            texto: $posto->continuar_rotacao
                ? 'A fila deste posto continua de onde parou no mês anterior.'
                : 'A fila deste posto reinicia na posição 1 no primeiro dia do mês.'
        );
    }

    // -----------------------------------------------------------------
    // Equipe
    // -----------------------------------------------------------------

    public function abrirPosto(int $postoId): void
    {
        $this->postoAberto = $this->postoAberto === $postoId ? null : $postoId;
        $this->buscaMotorista = '';
    }

    public function lotar(int $postoId, int $posicao, ?int $motoristaId, MontadorDeEscala $montador): void
    {
        if (blank($motoristaId)) {
            $this->liberarPosicao($postoId, $posicao);

            return;
        }

        try {
            $montador->lotarMotorista($this->posto($postoId), $motoristaId, $posicao);
        } catch (RuntimeException $e) {
            $this->dispatch('aviso', tipo: 'erro', texto: $e->getMessage());

            return;
        }

        $this->buscaMotorista = '';
        $this->limparCache();
    }

    public function liberarPosicao(int $postoId, int $posicao): void
    {
        EscalaLotacao::query()
            ->where('escala_posto_id', $postoId)
            ->where('posicao', $posicao)
            ->update(['escala_posto_id' => null, 'posicao' => null, 'plantoes_previstos' => 0]);

        $this->limparCache();
    }

    /**
     * Move o motorista uma posicao acima ou abaixo, trocando com o vizinho.
     *
     * Reordenar altera em que dia cada um pega plantao, o que a coordenacao usa
     * para acomodar pedidos dos motoristas.
     */
    public function mover(int $postoId, int $posicao, int $direcao): void
    {
        $posto = $this->posto($postoId);
        $destino = $posicao + $direcao;

        if ($destino < 1 || $destino > $posto->vagas()) {
            return;
        }

        $atual = EscalaLotacao::query()->where('escala_posto_id', $postoId)->where('posicao', $posicao)->first();
        $vizinho = EscalaLotacao::query()->where('escala_posto_id', $postoId)->where('posicao', $destino)->first();

        if ($atual === null) {
            return;
        }

        // A restricao unica (posto, posicao) exige liberar a posicao antes.
        $atual->update(['posicao' => null]);
        $vizinho?->update(['posicao' => $posicao]);
        $atual->update(['posicao' => $destino]);

        $this->limparCache();
    }

    /** Preenche as vagas abertas com quem ainda esta disponivel. */
    public function preencherAutomaticamente(MontadorDeEscala $montador): void
    {
        $preenchidas = $montador->preencherVagasAutomaticamente($this->escala->fresh());

        $this->limparCache();

        if ($preenchidas === 0) {
            $this->dispatch('aviso', tipo: 'atencao', texto: 'Nenhuma vaga foi preenchida: não há motoristas disponíveis.');

            return;
        }

        $this->dispatch(
            'aviso',
            tipo: 'sucesso',
            texto: "{$preenchidas} vaga(s) preenchida(s) em ordem alfabética. Reordene as posições conforme a necessidade."
        );
    }

    /** Gera os plantoes e leva para a visualizacao da planilha. */
    public function gerarPlantoes(GeradorDeEscala $gerador)
    {
        $resultado = $gerador->gerar($this->escala->fresh());

        if ($resultado->temErros()) {
            $this->dispatch('aviso', tipo: 'erro', texto: $resultado->erros()[0]->mensagem);

            return null;
        }

        session()->flash(
            $resultado->diasDescobertos > 0 ? 'atencao' : 'sucesso',
            'Plantões gerados — '.$resultado->resumo().'.'
        );

        return $this->redirectRoute('escalas.show', $this->escala, navigate: true);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function posto(int $postoId): EscalaPosto
    {
        return EscalaPosto::query()
            ->where('escala_id', $this->escala->id)
            ->with('ambulancia', 'unidade')
            ->findOrFail($postoId);
    }

    private function limparCache(): void
    {
        unset(
            $this->postos,
            $this->analisador,
            $this->resumo,
            $this->alertas,
            $this->candidatos,
            $this->ambulanciasLivres,
        );
    }

    public function render()
    {
        return view('livewire.montar-escala');
    }
}
