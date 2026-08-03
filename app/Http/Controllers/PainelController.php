<?php

namespace App\Http\Controllers;

use App\Enums\StatusMotorista;
use App\Models\Ambulancia;
use App\Models\Escala;
use App\Models\EscalaPlantao;
use App\Models\Motorista;
use App\Models\Unidade;
use App\Services\Escalas\AnalisadorDeEfetivo;
use Illuminate\View\View;

/**
 * Tela inicial: situacao do mes corrente e pendencias que exigem acao.
 */
class PainelController extends Controller
{
    public function __invoke(): View
    {
        $hoje = now();

        $escalaAtual = Escala::query()
            ->doMes($hoje->year, $hoje->month)
            ->with('postos.lotacoes', 'lotacoes')
            ->first();

        $proximaEscala = Escala::query()
            ->where(fn ($q) => $q->where('ano', '>', $hoje->year)
                ->orWhere(fn ($q2) => $q2->where('ano', $hoje->year)->where('mes', '>', $hoje->month)))
            ->orderBy('ano')
            ->orderBy('mes')
            ->first();

        return view('painel.index', [
            'escalaAtual' => $escalaAtual,
            'proximaEscala' => $proximaEscala,
            'resumo' => $escalaAtual ? AnalisadorDeEfetivo::para($escalaAtual)->resumo() : null,
            'alertas' => $escalaAtual ? AnalisadorDeEfetivo::para($escalaAtual)->alertas() : [],
            'plantoesDeHoje' => $this->plantoesDeHoje(),
            'contagens' => [
                'motoristas' => Motorista::query()->where('status', StatusMotorista::Ativo)->count(),
                'motoristas_inativos' => Motorista::query()->where('status', StatusMotorista::Inativo)->count(),
                'unidades' => Unidade::query()->where('ativo', true)->count(),
                'ambulancias' => Ambulancia::query()->where('ativo', true)->count(),
            ],
            'pendencias' => $this->pendenciasDeCadastro(),
            'ultimasEscalas' => Escala::query()->maisRecentes()->limit(6)->get(),
        ]);
    }

    /**
     * Quem esta de plantao agora, por unidade e ambulancia. E a pergunta que a
     * coordenacao mais recebe por telefone.
     */
    private function plantoesDeHoje()
    {
        return EscalaPlantao::query()
            ->whereDate('data', now()->toDateString())
            ->with(['motorista', 'posto.unidade', 'posto.ambulancia'])
            ->get()
            ->sortBy(fn (EscalaPlantao $p) => [$p->posto?->ordem ?? 0, $p->posto?->rotuloLotacao() ?? ''])
            ->values();
    }

    /**
     * Cadastros que precisam de atencao: CNH vencida ou vencendo e contrato
     * proximo do fim. Evita descobrir o problema so na hora de fechar a escala.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function pendenciasDeCadastro(): array
    {
        $ativos = Motorista::query()
            ->where('status', StatusMotorista::Ativo)
            ->ordenadoPorNome()
            ->get();

        return [
            'cnh_vencida' => $ativos->filter(fn (Motorista $m) => $m->cnhVencida())->values(),
            'cnh_vencendo' => $ativos->filter(fn (Motorista $m) => $m->cnhVencendo())->values(),
            'contrato_encerrado' => $ativos->filter(fn (Motorista $m) => $m->contratoEncerrado())->values(),
            'contrato_vencendo' => $ativos->filter(fn (Motorista $m) => $m->contratoVencendo())->values(),
            'sem_telefone' => $ativos->filter(fn (Motorista $m) => blank($m->telefone_1))->values(),
        ];
    }
}
