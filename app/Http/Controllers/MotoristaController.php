<?php

namespace App\Http\Controllers;

use App\Enums\AcaoAuditada;
use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Http\Requests\MotoristaRequest;
use App\Models\Escala;
use App\Models\Motorista;
use App\Services\Auditoria\Auditoria;
use App\Services\Documentos\ColunasDeMotoristas;
use App\Services\Documentos\GeradorDeDocumentos;
use App\Support\Aniversario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MotoristaController extends Controller
{
    public function index(Request $request): View
    {
        $motoristas = $this->filtrados($request)
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

    /**
     * Mesma lista da tela, em PDF.
     *
     * Exporta o recorte inteiro, e nao apenas a pagina aberta: quem filtra por
     * "somente com pendencias" quer a relacao completa para cobrar, nao os
     * primeiros vinte.
     */
    public function exportar(Request $request, GeradorDeDocumentos $gerador): Response
    {
        $pdf = $gerador->motoristas(
            $this->filtrados($request)->get(),
            $this->filtrosAplicados($request),
            ColunasDeMotoristas::de($request->input('colunas')),
        );

        $nome = 'motoristas-'.now()->format('Y-m-d').'.pdf';

        $filtros = $this->filtrosAplicados($request);

        Auditoria::registrar(
            AcaoAuditada::DocumentoEmitido,
            ($request->boolean('download') ? 'Baixou' : 'Abriu').' a lista de motoristas em PDF'.($filtros !== [] ? ' ('.implode('; ', $filtros).')' : ''),
            naoRepetirPor: 60,
        );

        return $request->boolean('download')
            ? $pdf->download($nome)
            : $pdf->stream($nome);
    }

    /**
     * Consulta com os filtros da tela, compartilhada pela listagem e pelo PDF.
     * Manter as duas no mesmo lugar e o que garante que o documento traga
     * exatamente o que estava sendo exibido.
     */
    private function filtrados(Request $request): Builder
    {
        $aniversario = $this->aniversarioPedido($request);

        return Motorista::query()
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
            ->when(
                $aniversario !== null,
                fn ($q) => Aniversario::aplicar($q, $aniversario)
            )
            // Filtrando aniversariantes, a ordem util e a do calendario.
            ->when(
                $aniversario !== null,
                fn ($q) => $q->ordenadoPorAniversario(),
                fn ($q) => $q->ordenadoPorNome()
            );
    }

    /**
     * Filtros em texto, impressos abaixo do titulo do PDF. Sem eles a folha
     * impressa nao diz por que aqueles motoristas estao ali, e nao outros.
     *
     * @return list<string>
     */
    private function filtrosAplicados(Request $request): array
    {
        $filtros = [];

        if (filled($busca = trim($request->string('busca')->toString()))) {
            $filtros[] = "Busca: \"{$busca}\"";
        }

        if ($request->filled('status')) {
            $status = StatusMotorista::tryFrom($request->string('status')->toString());
            $filtros[] = 'Situação: '.($status?->rotulo() ?? '—');
        }

        if ($request->filled('vinculo')) {
            $vinculo = Vinculo::tryFrom($request->string('vinculo')->toString());
            $filtros[] = 'Vínculo: '.($vinculo?->rotulo() ?? '—');
        }

        if ($request->boolean('irregulares')) {
            $filtros[] = 'Somente com pendências de CNH, contrato ou telefone';
        }

        if (($aniversario = $this->aniversarioPedido($request)) !== null) {
            $filtros[] = Aniversario::descricao($aniversario);
        }

        return $filtros;
    }

    /** Valor do filtro de aniversariantes, ou null quando nao ha um valido. */
    private function aniversarioPedido(Request $request): ?string
    {
        $valor = $request->string('aniversario')->toString();

        return Aniversario::valido($valor) ? $valor : null;
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

        Auditoria::criacao(AcaoAuditada::MotoristaCriado, $motorista, "Cadastrou o motorista {$motorista->nome_curto}");

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

        // Historico e proximos plantoes so das escalas que valem: propostas
        // alternativas e descartadas mostrariam dias que talvez nao acontecam.
        $vigentes = Escala::idsVigentes();

        return view('motoristas.show', [
            'motorista' => $motorista,
            'lotacoes' => $motorista->lotacoes
                ->filter(fn ($l) => in_array($l->escala_id, $vigentes, true))
                ->sortByDesc(fn ($l) => [$l->escala->ano, $l->escala->mes])
                ->take(12)
                ->values(),
            'proximosPlantoes' => $motorista->plantoes()
                ->whereIn('escala_id', $vigentes ?: [0])
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

        Auditoria::alteracao(AcaoAuditada::MotoristaAlterado, $motorista, "Alterou o cadastro de {$motorista->nome_curto}");

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

            Auditoria::alteracao(AcaoAuditada::MotoristaInativado, $motorista, "Pediu para excluir {$nome}, que participa de escalas: ficou inativo");

            return redirect()
                ->route('motoristas.index')
                ->with('atencao', "{$nome} participa de escalas já registradas e por isso foi marcado como inativo, preservando o histórico.");
        }

        $motorista->delete();

        Auditoria::exclusao(AcaoAuditada::MotoristaExcluido, $motorista, "Excluiu o motorista {$nome}");

        return redirect()
            ->route('motoristas.index')
            ->with('sucesso', "Motorista {$nome} excluído.");
    }
}
