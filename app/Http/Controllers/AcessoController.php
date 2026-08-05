<?php

namespace App\Http\Controllers;

use App\Enums\MotivoDeAcesso;
use App\Models\TentativaDeAcesso;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Monitoramento das tentativas de entrada no sistema.
 *
 * Restrito ao administrador pelo middleware "admin" declarado na rota.
 */
class AcessoController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filtro = $request->string('filtro')->toString();

        $tentativas = TentativaDeAcesso::query()
            ->when($filtro === 'falhas', fn ($c) => $c->falhas())
            ->when($filtro === 'sucessos', fn ($c) => $c->where('sucesso', true))
            ->maisRecentesPrimeiro()
            ->paginate(50)
            ->withQueryString();

        $desde = now()->subDay();

        return view('acessos.index', [
            'tentativas' => $tentativas,
            'filtro' => $filtro,
            'falhasNoDia' => TentativaDeAcesso::query()->falhas()->desde($desde)->count(),
            'entradasNoDia' => TentativaDeAcesso::query()->where('sucesso', true)->desde($desde)->count(),

            // Origens que mais erraram nas ultimas 24h. E o sinal mais direto
            // de varredura: um mesmo computador insistindo muitas vezes.
            'origensInsistentes' => TentativaDeAcesso::query()
                ->falhas()
                ->desde($desde)
                ->selectRaw('ip, COUNT(*) as total, COUNT(DISTINCT usuario) as usuarios, MAX(created_at) as ultima')
                ->groupBy('ip')
                ->havingRaw('COUNT(*) >= 3')
                ->orderByDesc('total')
                ->limit(5)
                ->get(),

            'motivos' => MotivoDeAcesso::cases(),
        ]);
    }
}
