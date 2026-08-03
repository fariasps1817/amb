<?php

namespace App\Http\Controllers;

use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Http\Requests\MotoristaRequest;
use App\Models\Motorista;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MotoristaController extends Controller
{
    public function index(Request $request): View
    {
        $motoristas = Motorista::query()
            ->busca($request->string('busca')->toString())
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')->toString())
            )
            ->when(
                $request->filled('vinculo'),
                fn ($q) => $q->where('vinculo', $request->string('vinculo')->toString())
            )
            ->when(
                $request->boolean('irregulares'),
                fn ($q) => $q->where(fn ($sub) => $sub
                    ->whereDate('cnh_validade', '<', now())
                    ->orWhere(fn ($c) => $c->where('vinculo', Vinculo::Contrato)->whereDate('vinculo_fim', '<', now()))
                    ->orWhereNull('telefone_1'))
            )
            ->ordenadoPorNome()
            ->paginate(20)
            ->withQueryString();

        return view('motoristas.index', [
            'motoristas' => $motoristas,
            'totais' => [
                'ativos' => Motorista::query()->where('status', StatusMotorista::Ativo)->count(),
                'inativos' => Motorista::query()->where('status', StatusMotorista::Inativo)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('motoristas.form', [
            'motorista' => new Motorista(['status' => StatusMotorista::Ativo, 'vinculo' => Vinculo::Contrato]),
        ]);
    }

    public function store(MotoristaRequest $request): RedirectResponse
    {
        $motorista = Motorista::query()->create($request->validated());

        return redirect()
            ->route('motoristas.index')
            ->with('sucesso', "Motorista {$motorista->nome_curto} cadastrado.");
    }

    public function show(Motorista $motorista): View
    {
        // Historico de plantoes dos ultimos meses, para consulta rapida.
        $motorista->load([
            'lotacoes.escala',
            'lotacoes.posto.unidade',
            'lotacoes.posto.ambulancia',
        ]);

        return view('motoristas.show', [
            'motorista' => $motorista,
            'lotacoes' => $motorista->lotacoes
                ->sortByDesc(fn ($l) => [$l->escala->ano, $l->escala->mes])
                ->take(12)
                ->values(),
            'proximosPlantoes' => $motorista->plantoes()
                ->whereDate('data', '>=', now()->toDateString())
                ->with('posto.unidade', 'posto.ambulancia')
                ->porData()
                ->limit(10)
                ->get(),
        ]);
    }

    public function edit(Motorista $motorista): View
    {
        return view('motoristas.form', ['motorista' => $motorista]);
    }

    public function update(MotoristaRequest $request, Motorista $motorista): RedirectResponse
    {
        $motorista->update($request->validated());

        return redirect()
            ->route('motoristas.index')
            ->with('sucesso', "Cadastro de {$motorista->nome_curto} atualizado.");
    }

    /**
     * Exclusao logica. O motorista permanece no historico das escalas antigas,
     * por isso nunca e removido de fato do banco.
     */
    public function destroy(Motorista $motorista): RedirectResponse
    {
        abort_unless(auth()->user()->podeEditar(), 403);

        $nome = $motorista->nome_curto;

        // Se ja participou de alguma escala, apenas inativa: excluir apagaria as
        // lotacoes por cascata e desfiguraria os documentos ja emitidos.
        if ($motorista->lotacoes()->exists()) {
            $motorista->update(['status' => StatusMotorista::Inativo]);

            return redirect()
                ->route('motoristas.index')
                ->with('atencao', "{$nome} participa de escalas já registradas e por isso foi marcado como inativo, preservando o histórico.");
        }

        $motorista->delete();

        return redirect()
            ->route('motoristas.index')
            ->with('sucesso', "Motorista {$nome} excluído.");
    }
}
