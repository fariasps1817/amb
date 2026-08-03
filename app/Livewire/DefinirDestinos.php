<?php

namespace App\Livewire;

use App\Enums\TipoDestino;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\Unidade;
use App\Services\Escalas\AnalisadorDeEfetivo;
use App\Services\Escalas\MontadorDeEscala;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Fechamento do mes: garantir destino para todo motorista ativo.
 *
 * Depois de lotar as ambulancias, os motoristas que "sobraram" precisam ser
 * classificados — reserva/sobreaviso, apoio em carro extra, ferias, licenca,
 * atestado. Sem isso a lista mensal de ocorrencias sairia incompleta e a escala
 * nao pode ser publicada.
 */
class DefinirDestinos extends Component
{
    public Escala $escala;

    /** Filtro da lista: todos | escalados | com destino | pendentes. */
    public string $filtro = 'pendentes';

    public string $busca = '';

    /** Lotacao aberta para edicao de periodo e observacao. */
    public ?int $emEdicao = null;

    public ?string $periodoInicio = null;

    public ?string $periodoFim = null;

    public ?string $observacao = null;

    public ?int $plantoesPrevistos = null;

    public ?int $unidadeApoioId = null;

    public function mount(Escala $escala): void
    {
        abort_unless($escala->editavel(), 403);

        $this->escala = $escala;

        // Se nao houver pendencia, abre direto na lista completa.
        if (AnalisadorDeEfetivo::para($escala->load('postos.lotacoes', 'lotacoes'))->motoristasSemDefinicao()->isEmpty()) {
            $this->filtro = 'todos';
        }
    }

    // -----------------------------------------------------------------
    // Dados
    // -----------------------------------------------------------------

    #[Computed]
    public function analisador(): AnalisadorDeEfetivo
    {
        return AnalisadorDeEfetivo::para(
            $this->escala->fresh()->load('postos.lotacoes', 'lotacoes')
        );
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

    #[Computed]
    public function lotacoes(): Collection
    {
        $lotacoes = EscalaLotacao::query()
            ->where('escala_id', $this->escala->id)
            ->with(['motorista', 'posto.unidade', 'posto.ambulancia', 'unidadeApoio'])
            ->get()
            ->filter(fn (EscalaLotacao $l) => $l->motorista !== null);

        $lotacoes = match ($this->filtro) {
            'escalados' => $lotacoes->filter(fn ($l) => $l->escalado()),
            'destino' => $lotacoes->filter(fn ($l) => ! $l->escalado() && $l->tipo_destino !== null),
            'pendentes' => $lotacoes->filter(fn ($l) => ! $l->definido()),
            default => $lotacoes,
        };

        $termo = trim($this->busca);

        if ($termo !== '') {
            $termo = mb_strtolower($termo);
            $lotacoes = $lotacoes->filter(fn (EscalaLotacao $l) => str_contains(mb_strtolower($l->motorista->nome_completo), $termo)
                || str_contains(mb_strtolower($l->motorista->nome_curto), $termo)
            );
        }

        return $lotacoes
            ->sortBy(fn (EscalaLotacao $l) => $l->motorista->nome_completo)
            ->values();
    }

    #[Computed]
    public function contagens(): array
    {
        $todas = EscalaLotacao::query()->where('escala_id', $this->escala->id)->get();

        return [
            'todos' => $todas->count(),
            'escalados' => $todas->filter(fn ($l) => $l->escalado())->count(),
            'destino' => $todas->filter(fn ($l) => ! $l->escalado() && $l->tipo_destino !== null)->count(),
            'pendentes' => $todas->filter(fn ($l) => ! $l->definido())->count(),
        ];
    }

    #[Computed]
    public function unidades(): Collection
    {
        return Unidade::query()->ativas()->ordenada()->get();
    }

    // -----------------------------------------------------------------
    // Ações
    // -----------------------------------------------------------------

    /**
     * Define o destino de um motorista. Passar null limpa a definicao, deixando
     * o motorista pendente de novo.
     */
    public function definir(int $motoristaId, ?string $tipo, MontadorDeEscala $montador): void
    {
        if (blank($tipo)) {
            EscalaLotacao::query()
                ->where('escala_id', $this->escala->id)
                ->where('motorista_id', $motoristaId)
                ->update([
                    'tipo_destino' => null,
                    'unidade_apoio_id' => null,
                    'periodo_inicio' => null,
                    'periodo_fim' => null,
                    'plantoes_previstos' => 0,
                    'observacao' => null,
                ]);

            $this->limparCache();

            return;
        }

        $montador->definirDestino($this->escala, $motoristaId, TipoDestino::from($tipo));

        $this->limparCache();
    }

    /** Abre o painel de detalhes (periodo, observacao, plantoes previstos). */
    public function editar(int $lotacaoId): void
    {
        if ($this->emEdicao === $lotacaoId) {
            $this->fecharEdicao();

            return;
        }

        $lotacao = $this->lotacao($lotacaoId);

        $this->emEdicao = $lotacaoId;
        $this->periodoInicio = $lotacao->periodo_inicio?->toDateString();
        $this->periodoFim = $lotacao->periodo_fim?->toDateString();
        $this->observacao = $lotacao->observacao;
        $this->plantoesPrevistos = $lotacao->plantoes_previstos;
        $this->unidadeApoioId = $lotacao->unidade_apoio_id;
    }

    public function fecharEdicao(): void
    {
        $this->reset(['emEdicao', 'periodoInicio', 'periodoFim', 'observacao', 'plantoesPrevistos', 'unidadeApoioId']);
    }

    public function salvarDetalhes(): void
    {
        $this->validate([
            'periodoInicio' => ['nullable', 'date'],
            'periodoFim' => ['nullable', 'date', 'after_or_equal:periodoInicio'],
            'observacao' => ['nullable', 'string', 'max:255'],
            'plantoesPrevistos' => ['nullable', 'integer', 'min:0', 'max:31'],
            'unidadeApoioId' => ['nullable', 'exists:unidades,id'],
        ], attributes: [
            'periodoInicio' => 'início do período',
            'periodoFim' => 'fim do período',
            'observacao' => 'observação',
            'plantoesPrevistos' => 'plantões previstos',
            'unidadeApoioId' => 'unidade de apoio',
        ]);

        $lotacao = $this->lotacao($this->emEdicao);

        $lotacao->update([
            'periodo_inicio' => $this->periodoInicio,
            'periodo_fim' => $this->periodoFim,
            'observacao' => $this->observacao,
            // Para quem esta escalado o total vem da contagem de plantoes e nao
            // deve ser sobrescrito aqui.
            'plantoes_previstos' => $lotacao->escalado()
                ? $lotacao->plantoes_previstos
                : ($this->plantoesPrevistos ?? 0),
            'unidade_apoio_id' => $lotacao->tipo_destino === TipoDestino::Apoio ? $this->unidadeApoioId : null,
        ]);

        $this->fecharEdicao();
        $this->limparCache();

        $this->dispatch('aviso', tipo: 'sucesso', texto: 'Detalhes atualizados.');
    }

    /**
     * Atalho de fechamento: manda todos os pendentes para reserva/sobreaviso.
     */
    public function todosParaReserva(MontadorDeEscala $montador): void
    {
        $total = $montador->definirRestantesComoReserva($this->escala->fresh()->load('postos', 'lotacoes'));

        $this->limparCache();

        if ($total === 0) {
            $this->dispatch('aviso', tipo: 'atencao', texto: 'Nenhum motorista pendente.');

            return;
        }

        $this->dispatch(
            'aviso',
            tipo: 'sucesso',
            texto: "{$total} motorista(s) definido(s) como reserva/sobreaviso."
        );
    }

    public function filtrar(string $filtro): void
    {
        $this->filtro = $filtro;
        $this->fecharEdicao();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function lotacao(int $id): EscalaLotacao
    {
        return EscalaLotacao::query()
            ->where('escala_id', $this->escala->id)
            ->findOrFail($id);
    }

    private function limparCache(): void
    {
        unset($this->analisador, $this->resumo, $this->alertas, $this->lotacoes, $this->contagens);
    }

    public function render()
    {
        return view('livewire.definir-destinos');
    }
}
