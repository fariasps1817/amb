<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\Motorista;
use App\Services\Documentos\GeradorDeDocumentos;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emissao dos documentos oficiais da escala mensal.
 *
 * Por padrao os PDFs abrem no navegador, para conferencia antes de imprimir;
 * com ?download=1 o arquivo e baixado.
 */
class DocumentoController extends Controller
{
    public function __construct(private readonly GeradorDeDocumentos $gerador) {}

    public function index(Escala $escala): View
    {
        $escala->load(['postos.unidade', 'postos.ambulancia', 'lotacoes.motorista']);

        $layout = Configuracao::atual()->layout_planilha ?: GeradorDeDocumentos::LAYOUT_CLASSICO;

        return view('documentos.index', [
            'escala' => $escala,
            'layoutAtual' => $layout,
            'layoutAlternativo' => $layout === GeradorDeDocumentos::LAYOUT_AGRUPADO
                ? GeradorDeDocumentos::LAYOUT_CLASSICO
                : GeradorDeDocumentos::LAYOUT_AGRUPADO,
            'totalEscalados' => $escala->lotacoes->filter(fn ($l) => $l->escalado())->count(),
            'totalLinhasOcorrencias' => $escala->lotacoes->count(),
            'totalPlantoes' => $escala->plantoes()->count(),
            'escalados' => $escala->lotacoes
                ->filter(fn ($l) => $l->escalado() && $l->motorista !== null)
                ->sortBy(fn ($l) => $l->motorista->nome_completo)
                ->values(),
        ]);
    }

    /**
     * Planilha mensal de plantoes, em paisagem.
     *
     * O layout vem por parametro (?layout=classico|agrupado) e, quando ausente,
     * usa o que estiver definido na identidade institucional.
     *
     * A pagina final com os condutores fora de escala vem por padrao — e o que o
     * RH espera junto da escala — e pode ser omitida com ?fora_de_escala=0,
     * quando a copia se destina apenas as unidades.
     */
    public function planilha(Request $request, Escala $escala): Response
    {
        $layout = $request->string('layout')->toString()
            ?: Configuracao::atual()->layout_planilha;

        $incluirForaDeEscala = ! $request->has('fora_de_escala')
            || $request->boolean('fora_de_escala');

        return $this->responder(
            $request,
            $this->gerador->planilha($escala, $layout, $incluirForaDeEscala),
            $this->gerador->nomeArquivo($escala, 'escala'),
        );
    }

    /** Lista mensal de ocorrencias. */
    public function ocorrencias(Request $request, Escala $escala): Response
    {
        return $this->responder(
            $request,
            $this->gerador->ocorrencias($escala),
            $this->gerador->nomeArquivo($escala, 'ocorrencias'),
        );
    }

    /** Folhas de frequencia de todos os motoristas escalados, uma por pagina. */
    public function frequencias(Request $request, Escala $escala): Response
    {
        return $this->responder(
            $request,
            $this->gerador->frequencias($escala),
            $this->gerador->nomeArquivo($escala, 'frequencias'),
        );
    }

    /** Folha de frequencia de um motorista. */
    public function frequencia(Request $request, Escala $escala, Motorista $motorista): Response
    {
        return $this->responder(
            $request,
            $this->gerador->frequencia($escala, $motorista),
            $this->gerador->nomeArquivo($escala, 'frequencia', Str::slug($motorista->nome_curto)),
        );
    }

    private function responder(Request $request, DomPdf $pdf, string $nome): Response
    {
        return $request->boolean('download')
            ? $pdf->download($nome)
            : $pdf->stream($nome);
    }
}
