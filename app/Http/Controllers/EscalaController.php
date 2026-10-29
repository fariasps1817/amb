<?php

namespace App\Http\Controllers;

use App\Enums\StatusEscala;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\Alerta;
use App\Services\Escalas\AnalisadorDeEfetivo;
use App\Services\Escalas\GeradorDeEscala;
use App\Services\Escalas\MontadorDeEscala;
use App\Services\Escalas\ValidadorDeEscala;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            ->orderBy('id')
            ->withCount(['postos', 'plantoes'])
            ->paginate(15);

        // Quantas escalas cada mes tem: mais de uma sao propostas alternativas.
        $porMes = Escala::query()
            ->selectRaw('ano * 100 + mes as chave, count(*) as total')
            ->groupBy('chave')
            ->pluck('total', 'chave');

        return view('escalas.index', ['escalas' => $escalas, 'porMes' => $porMes]);
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
            'publicador',
        ]);

        $validador = ValidadorDeEscala::para($escala);
        $doMes = $escala->doMesmoMes();

        return view('escalas.show', [
            'escala' => $escala,
            // Propostas alternativas do mesmo mes, para alternar e publicar.
            'doMes' => $doMes,
            'outrasPropostas' => $doMes->filter(fn (Escala $e) => $e->ehRascunho() && ! $e->is($escala))->values(),
            'mesTemOficial' => $doMes->contains(fn (Escala $e) => $e->oficial()),
            'resumo' => AnalisadorDeEfetivo::para($escala)->resumo(),
            'alertas' => $this->semAsJaAceitas($escala, $validador->validar()),
            'podePublicar' => $validador->podePublicar(),
            // Publicar aceitando as pendencias, com motivo; as que sempre
            // bloqueiam ficam marcadas na lista.
            'podePublicarComPendencias' => $validador->podePublicarComPendencias(),
            'bloqueios' => $validador->errosQueSempreBloqueiam(),
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
    public function montar(Request $request, Escala $escala): View
    {
        abort_unless($request->user()->podeEditar(), 403);
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
    public function destinos(Request $request, Escala $escala): View
    {
        // Antes de tudo: sincronizarEfetivo() abaixo ja grava no banco.
        abort_unless($request->user()->podeEditar(), 403);
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
        abort_unless($escala->ehRascunho(), 403, 'Só uma escala em rascunho pode ser publicada.');

        $dados = $request->validate([
            // O que fazer com as outras propostas do mes: excluir (padrao) ou
            // guardar como descartadas, para consulta.
            'outras' => ['nullable', 'in:excluir,descartar'],
            'aprovada_por' => ['nullable', 'string', 'max:120'],
            'aprovada_em' => ['nullable', 'date', 'before_or_equal:today'],
            // Publicar mesmo com pendencias: so com ciencia marcada e motivo.
            'aceitar_pendencias' => ['nullable', 'boolean'],
            'justificativa' => ['nullable', 'string', 'max:1000'],
        ], attributes: [
            'aprovada_por' => 'quem aprovou',
            'aprovada_em' => 'data da aprovação',
            'justificativa' => 'motivo',
        ]);

        $escala->load(['postos.lotacoes', 'postos.plantoes', 'lotacoes.motorista']);
        $validador = ValidadorDeEscala::para($escala);
        $erros = $validador->erros();

        // Algumas pendencias nao se aceitam: o mesmo motorista em duas
        // ambulancias no mesmo dia, ou escala sem plantoes.
        if ($bloqueios = $validador->errosQueSempreBloqueiam()) {
            return back()->with(
                'erro',
                'A escala não pode ser publicada. '.$bloqueios[0]->mensagem.' Esta pendência precisa ser corrigida antes.'
            );
        }

        if ($erros !== []) {
            if (! $request->boolean('aceitar_pendencias')) {
                return back()->with(
                    'erro',
                    'A escala tem '.count($erros).' pendência(s): '.$erros[0]->mensagem
                        .' Para publicar mesmo assim, confirme as pendências e informe o motivo.'
                );
            }

            if (mb_strlen(trim((string) ($dados['justificativa'] ?? ''))) < 10) {
                return back()
                    ->withErrors(['justificativa' => 'Explique em poucas palavras por que publicar com as pendências (pelo menos 10 letras).'])
                    ->withInput();
            }
        }

        $excluir = ($dados['outras'] ?? 'excluir') === 'excluir';

        try {
            $outras = $this->montador->publicar(
                $escala,
                $excluir,
                $dados['aprovada_por'] ?? null,
                filled($dados['aprovada_em'] ?? null) ? Carbon::parse($dados['aprovada_em']) : null,
                // Como estavam no momento: a escala pode mudar depois.
                array_map(fn ($a) => ['codigo' => $a->codigo, 'mensagem' => $a->mensagem], $erros),
                $erros !== [] ? $dados['justificativa'] : null,
            );
        } catch (RuntimeException|UniqueConstraintViolationException) {
            return back()->with('erro', 'Já existe uma escala publicada para '.$escala->referenciaLonga().'.');
        }

        $sobreAsOutras = match (true) {
            $outras === 0 => '',
            $excluir => " As outras {$outras} proposta(s) do mês foram excluídas.",
            default => " As outras {$outras} proposta(s) do mês foram guardadas como descartadas.",
        };

        $sobreAsPendencias = $erros === []
            ? ''
            : ' Com '.count($erros).' pendência(s) aceita(s) — o motivo ficou registrado.';

        return redirect()
            ->route('documentos.index', $escala)
            ->with('sucesso', "Escala de {$escala->titulo()} publicada.{$sobreAsPendencias}{$sobreAsOutras} Emita os documentos e envie as mensagens aos motoristas.");
    }

    public function reabrir(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless($escala->publicada(), 403, 'Só uma escala publicada pode ser reaberta.');

        $escala->update(['status' => StatusEscala::Rascunho, 'publicada_em' => null]);

        return back()->with('atencao', 'Escala reaberta para ajustes. Reemita os documentos após as alterações.');
    }

    // -----------------------------------------------------------------
    // Propostas alternativas do mes
    // -----------------------------------------------------------------

    /** Copia a proposta inteira como uma nova alternativa do mesmo mes. */
    public function duplicar(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        $dados = $request->validate(['nome' => ['nullable', 'string', 'max:60']]);

        try {
            $nova = $this->montador->duplicar($escala, $dados['nome'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()
            ->route('escalas.show', $nova)
            ->with('sucesso', "{$nova->nome} criada a partir de ".($escala->fresh()->nome ?? 'a escala original').'. Ajuste o que muda nesta alternativa.');
    }

    /** Nome e justificativa da proposta, que aparecem na comparação e nos documentos. */
    public function renomear(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_if($escala->oficial(), 403, 'O nome identifica propostas; a escala publicada não precisa dele.');

        $dados = $request->validate([
            'nome' => ['nullable', 'string', 'max:60'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['observacao' => 'descrição']);

        $repetido = filled($dados['nome'] ?? null) && Escala::query()
            ->doMes($escala->ano, $escala->mes)
            ->whereKeyNot($escala->id)
            ->where('nome', trim($dados['nome']))
            ->exists();

        if ($repetido) {
            return back()->withErrors(['nome' => 'Já existe outra proposta com esse nome neste mês.'])->withInput();
        }

        $escala->update([
            'nome' => filled($dados['nome'] ?? null) ? trim($dados['nome']) : null,
            'observacao' => $dados['observacao'] ?? null,
        ]);

        return back()->with('sucesso', 'Proposta atualizada.');
    }

    /** Devolve uma proposta descartada ao rascunho, enquanto o mês não tem publicada. */
    public function restaurar(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless($escala->descartada(), 403);

        try {
            $this->montador->restaurar($escala);
        } catch (RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', ($escala->nome ?? 'A proposta').' voltou para rascunho.');
    }

    public function arquivar(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless($escala->publicada(), 403, 'Só uma escala publicada pode ser arquivada.');

        $escala->update(['status' => StatusEscala::Arquivada]);

        return back()->with('sucesso', 'Escala arquivada. Ela permanece disponível apenas para consulta.');
    }

    public function destroy(Request $request, Escala $escala): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        // Escala publicada ja foi distribuida as unidades; exigir a reabertura
        // antes de excluir evita apagar por engano um mes em vigor.
        if ($escala->oficial()) {
            return back()->with('erro', 'Reabra a escala antes de excluí-la.');
        }

        $titulo = $escala->titulo();
        $escala->delete();

        return redirect()->route('escalas.index')->with('sucesso', "Escala de {$titulo} excluída.");
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Na escala publicada com pendencias aceitas, elas foram resolvidas por
     * quem publicou -- o descanso curto combinado com o motorista, a vaga
     * coberta por fora. Mostra-las de novo em vermelho passaria a ideia de
     * problema em aberto: ficam so no quadro do registro da publicacao.
     *
     * Sai so o que bate com o registro (mesmo codigo e mesma mensagem). Uma
     * pendencia que surja depois da publicacao continua aparecendo, e ao
     * reabrir a escala todas voltam, porque publicar de novo exige aceitar.
     *
     * @param  array<int, Alerta>  $alertas
     * @return array<int, Alerta>
     */
    private function semAsJaAceitas(Escala $escala, array $alertas): array
    {
        if (! $escala->publicadaComPendencias()) {
            return $alertas;
        }

        $aceitas = collect($escala->pendencias_aceitas)
            ->map(fn (array $p) => ($p['codigo'] ?? '').'|'.($p['mensagem'] ?? ''))
            ->all();

        return array_values(array_filter(
            $alertas,
            fn (Alerta $a) => ! $a->ehErro() || ! in_array($a->codigo.'|'.$a->mensagem, $aceitas, true)
        ));
    }

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
