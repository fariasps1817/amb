<?php

namespace App\Http\Controllers;

use App\Enums\AcaoAuditada;
use App\Http\Requests\UnidadeRequest;
use App\Models\Unidade;
use App\Services\Auditoria\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnidadeController extends Controller
{
    public function index(Request $request): View
    {
        $unidades = Unidade::query()
            ->busca($request->string('busca')->toString())
            ->when($request->filled('ativo'), fn ($q) => $q->where('ativo', $request->boolean('ativo')))
            ->withCount(['ambulancias' => fn ($q) => $q->where('ativo', true)])
            ->ordenada()
            ->paginate(20)
            ->withQueryString();

        return view('unidades.index', ['unidades' => $unidades]);
    }

    public function create(): View
    {
        return view('unidades.form', [
            'unidade' => new Unidade([
                'horas_trabalho' => 24,
                'horas_descanso' => 72,
                'ativo' => true,
                'uf' => 'CE',
            ]),
        ]);
    }

    public function store(UnidadeRequest $request): RedirectResponse
    {
        $unidade = Unidade::query()->create($request->dadosDaUnidade());

        Auditoria::criacao(AcaoAuditada::UnidadeCriada, $unidade, "Cadastrou a unidade {$unidade->sigla}");

        return redirect()
            ->route('unidades.index')
            ->with('sucesso', "Unidade {$unidade->sigla} cadastrada em regime {$unidade->regimeNotacao()}.");
    }

    public function show(Unidade $unidade): View
    {
        $unidade->load(['ambulancias' => fn ($q) => $q->orderBy('identificacao')->orderBy('placa')]);

        return view('unidades.show', ['unidade' => $unidade]);
    }

    public function edit(Unidade $unidade): View
    {
        return view('unidades.form', ['unidade' => $unidade]);
    }

    public function update(UnidadeRequest $request, Unidade $unidade): RedirectResponse
    {
        $regimeAnterior = $unidade->regimeNotacao();
        $unidade->update($request->dadosDaUnidade());

        Auditoria::alteracao(AcaoAuditada::UnidadeAlterada, $unidade, "Alterou o cadastro da unidade {$unidade->sigla}");

        $mensagem = "Unidade {$unidade->sigla} atualizada.";

        // Alterar o regime muda a quantidade de motoristas por ambulancia; os
        // meses ja montados guardam o regime antigo no proprio posto.
        if ($regimeAnterior !== $unidade->regimeNotacao()) {
            $mensagem .= " O regime passou de {$regimeAnterior} para {$unidade->regimeNotacao()}"
                ." ({$unidade->motoristasPorAmbulancia()} motoristas por ambulância)."
                .' As escalas já montadas mantêm o regime anterior.';
        }

        return redirect()->route('unidades.index')->with('sucesso', $mensagem);
    }

    public function destroy(Unidade $unidade): RedirectResponse
    {
        abort_unless(auth()->user()->podeEditar(), 403);

        $sigla = $unidade->sigla;

        // Unidade com historico de escala nao pode ser removida sem apagar os
        // postos por cascata; nesse caso apenas desativamos.
        if ($unidade->postos()->exists()) {
            $unidade->update(['ativo' => false]);

            Auditoria::alteracao(AcaoAuditada::UnidadeDesativada, $unidade, "Pediu para excluir a unidade {$sigla}, que consta em escalas: ficou desativada");

            return redirect()
                ->route('unidades.index')
                ->with('atencao', "A unidade {$sigla} consta em escalas já registradas e por isso foi desativada, preservando o histórico.");
        }

        if ($unidade->ambulancias()->exists()) {
            return redirect()
                ->route('unidades.index')
                ->with('erro', "A unidade {$sigla} tem ambulâncias vinculadas. Remaneje a frota antes de excluir.");
        }

        $unidade->delete();

        Auditoria::exclusao(AcaoAuditada::UnidadeExcluida, $unidade, "Excluiu a unidade {$sigla}");

        return redirect()->route('unidades.index')->with('sucesso', "Unidade {$sigla} excluída.");
    }
}
