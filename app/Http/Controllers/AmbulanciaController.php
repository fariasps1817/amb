<?php

namespace App\Http\Controllers;

use App\Http\Requests\AmbulanciaRequest;
use App\Models\Ambulancia;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmbulanciaController extends Controller
{
    public function index(Request $request): View
    {
        $ambulancias = Ambulancia::query()
            ->busca($request->string('busca')->toString())
            ->when($request->filled('unidade_id'), fn ($q) => $q->where('unidade_id', $request->integer('unidade_id')))
            ->when($request->filled('vinculo'), fn ($q) => $q->where('vinculo', $request->string('vinculo')->toString()))
            ->when($request->filled('ativo'), fn ($q) => $q->where('ativo', $request->boolean('ativo')))
            ->with('unidade')
            ->orderBy('unidade_id')
            ->orderBy('identificacao')
            ->orderBy('placa')
            ->paginate(20)
            ->withQueryString();

        return view('ambulancias.index', [
            'ambulancias' => $ambulancias,
            'unidades' => Unidade::query()->ordenada()->pluck('sigla', 'id'),
        ]);
    }

    public function create(): View
    {
        return view('ambulancias.form', [
            'ambulancia' => new Ambulancia(['ativo' => true, 'vinculo' => 'propria']),
            'unidades' => $this->opcoesDeUnidade(),
        ]);
    }

    public function store(AmbulanciaRequest $request): RedirectResponse
    {
        $ambulancia = Ambulancia::query()->create($request->validated());

        return redirect()
            ->route('ambulancias.index')
            ->with('sucesso', "Ambulância {$ambulancia->placaFormatada()} cadastrada.");
    }

    public function show(Ambulancia $ambulancia): View
    {
        $ambulancia->load('unidade');

        // Historico de uso nas escalas, do mes mais recente para o mais antigo.
        $postos = $ambulancia->postos()
            ->with(['escala', 'unidade', 'lotacoes.motorista'])
            ->get()
            ->sortByDesc(fn ($p) => [$p->escala->ano, $p->escala->mes])
            ->take(12)
            ->values();

        return view('ambulancias.show', [
            'ambulancia' => $ambulancia,
            'postos' => $postos,
        ]);
    }

    public function edit(Ambulancia $ambulancia): View
    {
        return view('ambulancias.form', [
            'ambulancia' => $ambulancia,
            'unidades' => $this->opcoesDeUnidade(),
        ]);
    }

    public function update(AmbulanciaRequest $request, Ambulancia $ambulancia): RedirectResponse
    {
        $ambulancia->update($request->validated());

        return redirect()
            ->route('ambulancias.index')
            ->with('sucesso', "Ambulância {$ambulancia->placaFormatada()} atualizada.");
    }

    public function destroy(Ambulancia $ambulancia): RedirectResponse
    {
        abort_unless(auth()->user()->podeEditar(), 403);

        $placa = $ambulancia->placaFormatada();

        // Veiculo que ja operou em alguma escala nao e removido, para nao apagar
        // os postos e plantoes daquele mes.
        if ($ambulancia->postos()->exists()) {
            $ambulancia->update(['ativo' => false]);

            return redirect()
                ->route('ambulancias.index')
                ->with('atencao', "A ambulância {$placa} consta em escalas já registradas e por isso foi desativada, preservando o histórico.");
        }

        $ambulancia->delete();

        return redirect()->route('ambulancias.index')->with('sucesso', "Ambulância {$placa} excluída.");
    }

    /**
     * Unidades ativas com o regime no rotulo, para o operador ver de imediato
     * quantos motoristas o veiculo vai exigir naquela lotacao.
     */
    private function opcoesDeUnidade(): array
    {
        return Unidade::query()
            ->ativas()
            ->ordenada()
            ->get()
            ->mapWithKeys(fn (Unidade $u) => [
                $u->id => sprintf(
                    '%s — %s (%s · %d motoristas)',
                    $u->sigla,
                    $u->nome,
                    $u->regimeNotacao(),
                    $u->motoristasPorAmbulancia(),
                ),
            ])
            ->all();
    }
}
