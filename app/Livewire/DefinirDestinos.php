<?php

namespace App\Livewire;

use App\Enums\AcaoAuditada;
use App\Enums\TipoDestino;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Auditoria\Auditoria;
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
        abort_unless(auth()->user()->podeEditar() && $escala->editavel(), 403);

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
            ->filter(fn (EscalaLotacao $l) => $l->motorista !== null && ! $l->foraDoEfetivo());

        $lotacoes = match ($this->filtro) {
            'escalados' => $lotacoes->filter(fn ($l) => $l->escalado()),
            'destino' => $lotacoes->filter(fn ($l) => ! $l->escalado() && $l->tipo_destino !== null),
            'pendentes' => $lotacoes->filter(fn ($l) => ! $l->definido()),
            'com_ocorrencia' => $lotacoes->filter(fn ($l) => filled($l->observacao)),
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
        $todas = EscalaLotacao::query()
            ->where('escala_id', $this->escala->id)
            ->with('motorista')
            ->get()
            ->reject(fn (EscalaLotacao $l) => $l->foraDoEfetivo());

        return [
            'todos' => $todas->count(),
            'escalados' => $todas->filter(fn ($l) => $l->escalado())->count(),
            'destino' => $todas->filter(fn ($l) => ! $l->escalado() && $l->tipo_destino !== null)->count(),
            'pendentes' => $todas->filter(fn ($l) => ! $l->definido())->count(),
            'com_ocorrencia' => $todas->filter(fn ($l) => filled($l->observacao))->count(),
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
        $motorista = Motorista::query()->find($motoristaId);
        $antes = $this->situacaoDe($motoristaId);

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

            Auditoria::registrar(
                AcaoAuditada::DestinoLimpo,
                "Limpou o destino de {$motorista?->nome_curto}",
                $motorista,
                $this->escala,
                [['campo' => 'Destino', 'de' => $antes, 'para' => null]],
            );

            $this->limparCache();

            return;
        }

        $destino = TipoDestino::from($tipo);

        $montador->definirDestino($this->escala, $motoristaId, $destino);

        Auditoria::registrar(
            AcaoAuditada::DestinoDefinido,
            "Definiu o destino de {$motorista?->nome_curto}: {$destino->rotulo()}",
            $motorista,
            $this->escala,
            [['campo' => 'Destino', 'de' => $antes, 'para' => $destino->rotulo()]],
        );

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
        // Abre com o valor que esta valendo hoje: o ajuste, se houver, ou a
        // contagem da escala.
        $this->plantoesPrevistos = $lotacao->plantoesEfetivos();
        $this->unidadeApoioId = $lotacao->unidade_apoio_id;
    }

    public function fecharEdicao(): void
    {
        $this->reset(['emEdicao', 'periodoInicio', 'periodoFim', 'observacao', 'plantoesPrevistos', 'unidadeApoioId']);
    }

    /**
     * Apaga a ocorrencia registrada, deixando a coluna em branco no documento.
     *
     * Para quem esta escalado o periodo so existe por causa da ocorrencia, entao
     * ele e limpo junto; para quem tem destino administrativo o periodo continua
     * valendo (e o das ferias, da licenca) e e preservado.
     */
    public function limparOcorrencia(): void
    {
        $lotacao = $this->lotacao($this->emEdicao);

        $lotacao->update([
            'observacao' => null,
            'periodo_inicio' => $lotacao->escalado() ? null : $lotacao->periodo_inicio,
            'periodo_fim' => $lotacao->escalado() ? null : $lotacao->periodo_fim,
            // O ajuste de plantões acompanhava a ocorrência; sem ela, volta a
            // valer a contagem da escala.
            'plantoes_ajustados' => $lotacao->escalado() ? null : $lotacao->plantoes_ajustados,
        ]);

        Auditoria::alteracao(AcaoAuditada::OcorrenciaRemovida, $lotacao, "Removeu a ocorrência de {$lotacao->motorista?->nome_curto}", $this->escala);

        $this->fecharEdicao();
        $this->limparCache();

        $this->dispatch('aviso', tipo: 'sucesso', texto: 'Ocorrência removida.');
    }

    public function salvarDetalhes(): void
    {
        $lotacao = $this->lotacao($this->emEdicao);

        $regras = [
            'periodoInicio' => ['nullable', 'date'],
            'periodoFim' => ['nullable', 'date', 'after_or_equal:periodoInicio'],
            'observacao' => ['nullable', 'string', 'max:255'],
            'plantoesPrevistos' => ['nullable', 'integer', 'min:0', 'max:31'],
            'unidadeApoioId' => ['nullable', 'exists:unidades,id'],
        ];

        // Quem esta escalado nao tem tipo de destino, entao o texto da ocorrencia
        // nao pode ser deduzido do periodo — sem descricao, a data informada nao
        // apareceria no documento e o registro se perderia em silencio.
        if ($lotacao->escalado() && filled($this->periodoInicio)) {
            $regras['observacao'] = ['required', 'string', 'max:255'];
        }

        $this->validate($regras, [
            'observacao.required' => 'Descreva a ocorrência: só a data não aparece no documento.',
        ], [
            'periodoInicio' => 'início do período',
            'periodoFim' => 'fim do período',
            'observacao' => 'ocorrência',
            'plantoesPrevistos' => 'plantões previstos',
            'unidadeApoioId' => 'unidade de apoio',
        ]);

        $informado = $this->plantoesPrevistos;

        $lotacao->update([
            'periodo_inicio' => $this->periodoInicio,
            'periodo_fim' => $this->periodoFim,
            'observacao' => $this->observacao,

            // Para quem esta escalado, plantoes_previstos continua sendo a
            // contagem da escala — refeita a cada geracao. O numero digitado vai
            // para plantoes_ajustados, que sobrevive a regeracao e prevalece no
            // documento; igual ao calculado, nao ha ajuste a guardar.
            'plantoes_previstos' => $lotacao->escalado()
                ? $lotacao->plantoes_previstos
                : ($informado ?? 0),

            'plantoes_ajustados' => $lotacao->escalado()
                && $informado !== null
                && (int) $informado !== (int) $lotacao->plantoes_previstos
                    ? (int) $informado
                    : null,

            'unidade_apoio_id' => $lotacao->tipo_destino === TipoDestino::Apoio ? $this->unidadeApoioId : null,
        ]);

        Auditoria::alteracao(AcaoAuditada::DetalhesDaLotacaoAlterados, $lotacao, "Alterou período, ocorrência ou plantões de {$lotacao->motorista?->nome_curto}", $this->escala);

        $this->fecharEdicao();
        $this->limparCache();

        $this->dispatch('aviso', tipo: 'sucesso', texto: 'Detalhes atualizados.');
    }

    /**
     * Atalho de fechamento: manda todos os pendentes para reserva/sobreaviso.
     */
    public function todosParaReserva(MontadorDeEscala $montador): void
    {
        $escala = $this->escala->fresh()->load('postos', 'lotacoes');
        $nomes = AnalisadorDeEfetivo::para($escala)->motoristasSemDefinicao()->pluck('nome_curto')->implode(', ');

        $total = $montador->definirRestantesComoReserva($escala);

        $this->limparCache();

        if ($total === 0) {
            $this->dispatch('aviso', tipo: 'atencao', texto: 'Nenhum motorista pendente.');

            return;
        }

        Auditoria::registrar(
            AcaoAuditada::RestantesParaReserva,
            "Definiu {$total} motorista(s) sem destino como reserva/sobreaviso",
            $this->escala,
            $this->escala,
            Auditoria::detalhes(['Motoristas' => $nomes]),
        );

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

    /** Onde o motorista esta no mes, em texto: a ambulancia ou o destino administrativo. */
    private function situacaoDe(int $motoristaId): ?string
    {
        $lotacao = EscalaLotacao::query()
            ->where('escala_id', $this->escala->id)
            ->where('motorista_id', $motoristaId)
            ->with('posto.unidade')
            ->first();

        return $lotacao?->escalado() ? $lotacao->posto?->rotuloLotacao() : $lotacao?->tipo_destino?->rotulo();
    }

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
