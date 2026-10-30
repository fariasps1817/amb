<?php

namespace App\Http\Controllers;

use App\Enums\AcaoAuditada;
use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\Motorista;
use App\Services\Auditoria\Auditoria;
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
            'totalLinhasOcorrencias' => $escala->lotacoes
                ->filter(fn ($l) => $l->motorista !== null && ! $l->foraDoEfetivo())
                ->count(),
            'totalPlantoes' => $escala->plantoes()->count(),

            // Recebem folha de frequencia os escalados e tambem os de
            // sobreaviso e apoio, que prestam plantao sem dia definido.
            'comFolhaDeFrequencia' => $escala->lotacoes
                ->filter(fn ($l) => $l->motorista !== null && ($l->escalado() || $l->disponivel()))
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
            $escala,
            'a planilha mensal de plantões',
        );
    }

    /** Lista mensal de ocorrencias. */
    public function ocorrencias(Request $request, Escala $escala): Response
    {
        return $this->responder(
            $request,
            $this->gerador->ocorrencias($escala),
            $this->gerador->nomeArquivo($escala, 'ocorrencias'),
            $escala,
            'a lista mensal de ocorrências',
        );
    }

    /**
     * Folhas de frequencia de todos os motoristas escalados, uma por pagina.
     *
     * Saem sem a hora de entrada e de saida: o RH preenche a mao no momento da
     * assinatura. Com ?horas=1 vem preenchidas, como era antes.
     */
    public function frequencias(Request $request, Escala $escala): Response
    {
        $comHoras = $request->boolean('horas');

        return $this->responder(
            $request,
            $this->gerador->frequencias($escala, $comHoras),
            $this->gerador->nomeArquivo($escala, 'frequencias', $comHoras ? 'com-horas' : null),
            $escala,
            'as folhas de frequência de todos os motoristas',
        );
    }

    /** Folha de frequencia de um motorista. */
    public function frequencia(Request $request, Escala $escala, Motorista $motorista): Response
    {
        $comHoras = $request->boolean('horas');

        // O sufixo separa as duas versoes na pasta de downloads, senao a
        // segunda emissao sobrescreve a primeira sem aviso.
        $sufixo = Str::slug($motorista->nome_curto).($comHoras ? '-com-horas' : '');

        return $this->responder(
            $request,
            $this->gerador->frequencia($escala, $motorista, $comHoras),
            $this->gerador->nomeArquivo($escala, 'frequencia', $sufixo),
            $escala,
            "a folha de frequência de {$motorista->nome_curto}",
            $motorista,
        );
    }

    /** Toda emissao passa por aqui, e por isso e aqui que entra na auditoria. */
    private function responder(Request $request, DomPdf $pdf, string $nome, Escala $escala, string $documento, ?Motorista $motorista = null): Response
    {
        Auditoria::registrar(
            AcaoAuditada::DocumentoEmitido,
            ($request->boolean('download') ? 'Baixou ' : 'Abriu ').$documento,
            $motorista,
            $escala,
            naoRepetirPor: 60,
        );

        return $request->boolean('download')
            ? $pdf->download($nome)
            : $pdf->stream($nome);
    }
}
