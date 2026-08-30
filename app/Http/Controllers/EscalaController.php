<?php

namespace App\Http\Controllers;

use App\Enums\StatusEscala;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\AnalisadorDeEfetivo;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Escalas\ValidadorDeEscala;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

class EscalaController extends Controller
{
    public function __construct(
        private readonly MontadorDeEscala $montador,
        private readonly GeradorDeEscala $gerador,
    ) {}

    public function index(): View
    {
        $escalas = Escala::query()
            ->maisRecentes()
            ->withCount(['postos', 'plantoes'])
            ->paginate(15);

        return view('escalas.index', ['escalas' => $escalas]);
    }

    public function create(): View
    {
        // Sugere o primeiro mes que ainda nao tem escala, a partir do corrente.
        $sugestao = now()->startOfMonth();

        while (Escala::query()->doMes($sugestao->year, $sugestao->month)->exists()) {
            $sugestao->addMonth();
        }

        return view('escalas.create', [
            'sugestao' => $sugestao,
            'ultimaEscala' => Escala::query()->maisRecentes()->first(),
            'frotaAtiva' => Ambulancia::query()->ativas()->whereNotNull('unidade_id')->count(),
            'demandaEstimada' => $this->demandaEstimada(),
            'motoristasAtivos' => Motorista::query()->ativos()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        $dados = $request->validate([
            'ano' => ['required', 'integer', 'min:2020', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'copiar_mes_anterior' => ['boolean'],
        ]);

        try {
            $escala = $this->montador->criar(
                (int) $dados['ano'],
                (int) $dados['mes'],
                $request->boolean('copiar_mes_anterior'),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('erro', $e->getMessage());
        }

        // Traz todo o efetivo ativo para a folha do mes, para a tela de destinos
        // ja listar quem falta definir.
        $this->montador->sincronizarEfetivo($escala);

        return redirect()
            ->route('escalas.montar', $escala)
            ->with('sucesso', "Escala de {$escala->referenciaLonga()} criada. Confira os postos e as equipes.");
    }

    public function show(Escala $escala): View
    {
        $escala->load([
            'postos.unidade',
            'postos.ambulancia',
            'postos.lotacoes.motorista',
            'postos.plantoes.motorista',
            'lotacoes.motorista',
            'autor',
        ]);

        $validador = ValidadorDeEscala::para($escala);

        return view('escalas.show', [
            'escala' => $escala,
            'resumo' => AnalisadorDeEfetivo::para($escala)->resumo(),
            'alertas' => $validador->validar(),
            'podePublicar' => $validador->podePublicar(),
            'dias' => $escala->dias(),
            'grade' => $this->montarGrade($escala),

            // A equipe de cada posto e listada na ordem em que entra no mes:
            // com a rotacao continua, quem assume o dia 1o pode ser a posicao 4.
            'ordensDeEntrada' => $escala->postos
                ->mapWithKeys(fn ($posto) => [$posto->id => app(GeradorDeEscala::class)->ordemDeEntrada($posto)])
                ->all(),
        ]);
    }

    /**
     * Tela de montagem: postos do mes e equipe de cada ambulancia.
     */
    public function montar(Escala $escala): View
    {
        abort_unless($escala->editavel(), 403, 'Escala arquivada não pode ser alterada.');

        $escala->load([
            'postos.unidade',
            'postos.ambulancia',
            'postos.lotacoes.motorista',
            'lotacoes.motorista',
        ]);

        $analisador = AnalisadorDeEfetivo::para($escala);

        return view('escalas.montar', [
            'escala' => $escala,
            'resumo' => $analisador->resumo(),
            'alertas' => $analisador->alertas(),
            'disponiveis' => $analisador->motoristasDisponiveisParaLotar(),
            'unidades' => Unidade::query()->ativas()->ordenada()->get(),
            'ambulanciasLivres' => $this->ambulanciasLivres($escala),
        ]);
    }

    /**
     * Tela de destinos: fechamento do mes, em que todo motorista ativo recebe
     * uma situacao (escalado, reserva, ferias, licenca...).
     */
    public function destinos(Escala $escala): View
    {
        abort_unless($escala->editavel(), 403, 'Escala arquivada não pode ser alterada.');

        $this->montador->sincronizarEfetivo($escala);

        $escala->load([
            'postos.unidade',
            'postos.ambulancia',
            'lotacoes.motorista',
            'lotacoes.posto.unidade',
            'lotacoes.unidadeApoio',
        ]);

        $analisador = AnalisadorDeEfetivo::para($escala);

        return view('escalas.destinos', [
            'escala' => $escala,
            'resumo' => $analisador->resumo(),
            'alertas' => $analisador->alertas(),
            'porDestino' => $analisador->porDestino(),
            'lotacoes' => $escala->lotacoes
                ->sortBy(fn ($l) => $l->motorista?->nome_completo ?? '')
                ->values(),
            'unidades' => Unidade::query()->ativas()->ordenada()->pluck('sigla', 'id'),
        ]);
    }

    public function gerar(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless($escala->editavel(), 403, 'Escala arquivada não pode ser alterada.');

        $resultado = $this->gerador->gerar($escala, $request->boolean('descartar_ajustes'));

        if ($resultado->temErros()) {
            return back()->with('erro', $resultado->erros()[0]->mensagem);
        }

        return redirect()
            ->route('escalas.show', $escala)
            ->with(
                $resultado->diasDescobertos > 0 ? 'atencao' : 'sucesso',
                'Plantões gerados — '.$resultado->resumo().'.'
            );
    }

    public function publicar(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        $escala->load(['postos.lotacoes', 'postos.plantoes', 'lotacoes.motorista']);
        $validador = ValidadorDeEscala::para($escala);

        if (! $validador->podePublicar()) {
            $erros = array_values(array_filter($validador->validar(), fn ($a) => $a->ehErro()));

            return back()->with(
                'erro',
                'A escala não pode ser publicada. '.$erros[0]->mensagem
                    .(count($erros) > 1 ? ' Há mais '.(count($erros) - 1).' pendência(s).' : '')
            );
        }

        $escala->update([
            'status' => StatusEscala::Publicada,
            'publicada_em' => now(),
        ]);

        return redirect()
            ->route('documentos.index', $escala)
            ->with('sucesso', "Escala de {$escala->referenciaLonga()} publicada. Emita os documentos e envie as mensagens aos motoristas.");
    }

    public function reabrir(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        $escala->update(['status' => StatusEscala::Rascunho, 'publicada_em' => null]);

        return back()->with('atencao', 'Escala reaberta para ajustes. Reemita os documentos após as alterações.');
    }

    public function arquivar(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        $escala->update(['status' => StatusEscala::Arquivada]);

        return back()->with('sucesso', 'Escala arquivada. Ela permanece disponível apenas para consulta.');
    }

    public function destroy(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        // Escala publicada ja foi distribuida as unidades; exigir a reabertura
        // antes de excluir evita apagar por engano um mes em vigor.
        if ($escala->publicada()) {
            return back()->with('erro', 'Reabra a escala antes de excluí-la.');
        }

        $referencia = $escala->referenciaLonga();
        $escala->delete();

        return redirect()->route('escalas.index')->with('sucesso', "Escala de {$referencia} excluída.");
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Grade da planilha: para cada posto, o plantao de cada dia do mes.
     *
     * Estrutura: [posto_id => ['AAAA-MM-DD' => EscalaPlantao]]
     *
     * @return array<int, array<string, EscalaPlantao>>
     */
    private function montarGrade(Escala $escala): array
    {
        $grade = [];

        foreach ($escala->postos as $posto) {
            $grade[$posto->id] = $posto->plantoes
                ->keyBy(fn ($plantao) => $plantao->data->toDateString())
                ->all();
        }

        return $grade;
    }

    /** Ambulancias que ainda nao ocupam nenhum posto desta escala. */
    private function ambulanciasLivres(Escala $escala): Collection
    {
        $ocupadas = $escala->postos->pluck('ambulancia_id')->filter()->all();

        return Ambulancia::query()
            ->ativas()
            ->whereNotIn('id', $ocupadas ?: [0])
            ->with('unidade')
            ->orderBy('identificacao')
            ->orderBy('placa')
            ->get();
    }

    /**
     * Quantos motoristas a frota ativa exigiria hoje, somando o regime de cada
     * unidade. Mostrado na tela de criacao para o operador saber, antes de
     * comecar, se o efetivo fecha.
     */
    private function demandaEstimada(): int
    {
        return Ambulancia::query()
            ->ativas()
            ->whereNotNull('unidade_id')
            ->with('unidade')
            ->get()
            ->sum(fn (Ambulancia $a) => $a->unidade?->motoristasPorAmbulancia() ?? 0);
    }
}
