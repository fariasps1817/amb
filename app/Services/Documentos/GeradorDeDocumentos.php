<?php

namespace App\Services\Documentos;

use App\Enums\TipoDestino;
use App\Models\Configuracao;
use App\Models\Escala;
use App\Models\EscalaLotacao;
use App\Models\Motorista;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Emissao dos tres documentos oficiais da escala mensal:
 *
 *   1. Planilha de plantoes — calendario distribuido as unidades.
 *   2. Lista mensal de ocorrencias — todo o efetivo com lotacao e plantoes.
 *   3. Folha de frequencia — uma por motorista, para assinatura.
 */
class GeradorDeDocumentos
{
    /**
     * Planilha mensal em paisagem.
     *
     * Com 31 colunas de dias mais os dados do condutor, o A4 em pe nao cabe; a
     * orientacao paisagem reproduz o documento que o setor ja usa.
     */
    /** Layouts disponiveis para a planilha mensal. */
    public const LAYOUT_CLASSICO = 'classico';

    public const LAYOUT_AGRUPADO = 'agrupado';

    public const LAYOUTS = [
        self::LAYOUT_CLASSICO => 'Clássico — colunas de placa e lotação à esquerda',
        self::LAYOUT_AGRUPADO => 'Agrupado — faixa de identificação por ambulância',
    ];

    public function planilha(Escala $escala, ?string $layout = null): DomPdf
    {
        $layout = array_key_exists((string) $layout, self::LAYOUTS)
            ? $layout
            : self::LAYOUT_CLASSICO;

        $dados = DadosDaPlanilha::para($escala);
        $config = Configuracao::atual();

        // Capacidades calibradas na pratica, medindo quando a quebra do dompdf
        // passa a ocorrer antes da nossa: se a pagina logica couber mais linhas
        // do que a folha comporta, o dompdf quebra sozinho no meio de uma
        // ambulancia e o rowspan de placa e lotacao fica orfao na folha seguinte.
        //
        // O layout agrupado gasta uma linha a mais por ambulancia com a faixa de
        // identificacao, entao comporta menos motoristas por folha.
        $paginas = $layout === self::LAYOUT_AGRUPADO
            ? $dados->paginas(linhasPorPagina: 32, linhasExtrasPorBloco: 1)
            : $dados->paginas(linhasPorPagina: 34);

        $view = $layout === self::LAYOUT_AGRUPADO
            ? 'documentos.pdf.planilha-agrupada'
            : 'documentos.pdf.planilha';

        $pdf = $this->pdf($view, [
            'escala' => $escala,
            'dados' => $dados,
            'paginas' => $paginas,
            'config' => $config,
        ])->setPaper('a4', 'landscape');

        return $this->aplicarRodape($pdf, $config, 'landscape');
    }

    /**
     * Lista mensal de ocorrencias: nome, lotacao, vinculo, plantoes previstos e
     * observacao de todo o efetivo, em ordem alfabetica.
     */
    public function ocorrencias(Escala $escala): DomPdf
    {
        $config = Configuracao::atual();

        $pdf = $this->pdf('documentos.pdf.ocorrencias', [
            'escala' => $escala,
            'linhas' => $this->linhasDeOcorrencias($escala),
            'config' => $config,
        ])->setPaper('a4', 'portrait');

        return $this->aplicarRodape($pdf, $config, 'portrait');
    }

    /**
     * Folhas de frequencia de todos os motoristas escalados, uma por pagina.
     */
    public function frequencias(Escala $escala): DomPdf
    {
        return $this->montarFrequencia($escala, $this->folhasDeFrequencia($escala));
    }

    /** Folha de frequencia de um unico motorista. */
    public function frequencia(Escala $escala, Motorista $motorista): DomPdf
    {
        return $this->montarFrequencia($escala, $this->folhasDeFrequencia($escala, $motorista));
    }

    private function montarFrequencia(Escala $escala, Collection $folhas): DomPdf
    {
        $config = Configuracao::atual();

        $pdf = $this->pdf('documentos.pdf.frequencia', [
            'escala' => $escala,
            'folhas' => $folhas,
            'config' => $config,
        ])->setPaper('a4', 'portrait');

        return $this->aplicarRodape($pdf, $config, 'portrait');
    }

    // -----------------------------------------------------------------
    // Dados dos documentos
    // -----------------------------------------------------------------

    /**
     * Linhas da lista mensal de ocorrencias.
     *
     * Contempla todo o efetivo do mes — escalados, reservas e afastados — em
     * ordem alfabetica pelo nome completo, como no documento oficial.
     *
     * @return Collection<int, array{
     *     numero: int, nome: string, lotacao: string, vinculo: string,
     *     plantoes: int|string, ocorrencia: string
     * }>
     */
    public function linhasDeOcorrencias(Escala $escala): Collection
    {
        $escala->loadMissing([
            'lotacoes.motorista',
            'lotacoes.posto.unidade',
            'lotacoes.posto.ambulancia',
            'lotacoes.unidadeApoio',
        ]);

        return $escala->lotacoes
            ->filter(fn (EscalaLotacao $l) => $l->motorista !== null)
            ->sortBy(fn (EscalaLotacao $l) => $this->chaveAlfabetica($l->motorista->nome_completo))
            ->values()
            ->map(fn (EscalaLotacao $l, int $i) => [
                'numero' => $i + 1,
                'nome' => $l->motorista->nomeDocumento(),
                'lotacao' => $l->rotuloLotacao(),
                'vinculo' => $l->motorista->vinculo->rotuloDocumento(),
                // Afastados aparecem com "~" no lugar do numero, como no
                // documento em uso, para deixar claro que nao ha previsao.
                'plantoes' => $this->plantoesDoDocumento($l),
                'ocorrencia' => $l->textoOcorrencia(),
            ]);
    }

    /**
     * Folhas de frequencia: uma por motorista escalado, com todos os dias do mes
     * e os dias de folga marcados.
     *
     * @return Collection<int, array{
     *     motorista: Motorista, regime: string, linhas: array<int, array>,
     *     total_plantoes: int
     * }>
     */
    public function folhasDeFrequencia(Escala $escala, ?Motorista $motorista = null): Collection
    {
        $escala->loadMissing(['lotacoes.motorista', 'lotacoes.posto']);

        return $escala->lotacoes
            ->filter(fn (EscalaLotacao $l) => $l->escalado() && $l->motorista !== null)
            ->when($motorista !== null, fn (Collection $c) => $c->where('motorista_id', $motorista->id))
            ->sortBy(fn (EscalaLotacao $l) => $this->chaveAlfabetica($l->motorista->nome_completo))
            ->values()
            ->map(function (EscalaLotacao $lotacao) use ($escala) {
                $plantoes = $escala->plantoes()
                    ->where('motorista_id', $lotacao->motorista_id)
                    ->get()
                    ->keyBy(fn ($p) => $p->data->toDateString());

                $linhas = [];

                foreach ($escala->dias() as $dia) {
                    $plantao = $plantoes->get($dia->toDateString());

                    $linhas[] = [
                        'entrada_data' => $dia->format('d/m'),
                        'entrada_hora' => $plantao?->horaEntradaTexto() ?? '07:00',
                        // A saida do plantao de 24 horas cai no dia seguinte.
                        'saida_data' => $dia->copy()->addDay()->format('d/m'),
                        'saida_hora' => $plantao?->horaSaidaTexto() ?? '07:00',
                        'plantao' => $plantao !== null,
                        'observacao' => $plantao?->observacao,
                    ];
                }

                return [
                    'motorista' => $lotacao->motorista,
                    'lotacao' => $lotacao->rotuloLotacao(),
                    'placa' => $lotacao->posto?->rotuloPlaca() ?? '',
                    'regime' => $lotacao->posto?->regimeNotacao() ?? '',
                    'linhas' => $linhas,
                    'total_plantoes' => $plantoes->count(),
                ];
            });
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Coluna PLANTOES do documento.
     *
     * Quem esta afastado o mes inteiro (ferias, licenca) recebe "~" em vez de
     * zero, distinguindo "nao ha previsao" de "previsto zero plantao", que e o
     * caso das reservas.
     */
    private function plantoesDoDocumento(EscalaLotacao $lotacao): int|string
    {
        $afastado = in_array($lotacao->tipo_destino, [
            TipoDestino::Ferias,
            TipoDestino::Licenca,
            TipoDestino::Atestado,
            TipoDestino::Cedido,
        ], true);

        return $afastado && $lotacao->plantoes_previstos === 0 ? '~' : $lotacao->plantoes_previstos;
    }

    /**
     * Chave de ordenacao alfabetica que ignora acentos, para "ÁLVARO" nao cair
     * depois de "ZULMIRA".
     */
    private function chaveAlfabetica(string $nome): string
    {
        return Str::transliterate(mb_strtoupper($nome));
    }

    /**
     * Escreve o rodape diretamente no canvas do PDF.
     *
     * Feito aqui, e nao no HTML, por dois motivos: um elemento com posicao fixa
     * declarado dentro do laco de paginas apareceria repetido e sobreposto, e a
     * numeracao "Pagina X de Y" so e conhecida depois da renderizacao — o dompdf
     * resolve os marcadores {PAGE_NUM} e {PAGE_COUNT} neste ponto.
     */
    private function aplicarRodape(DomPdf $pdf, Configuracao $config, string $orientacao): DomPdf
    {
        $pdf->render();

        $canvas = $pdf->getDomPDF()->getCanvas();

        // Dimensoes do A4 em pontos.
        [$largura, $altura] = $orientacao === 'landscape'
            ? [841.89, 595.28]
            : [595.28, 841.89];

        $y = $altura - 22;
        $fonte = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $tamanho = 6.5;
        $cor = [0.35, 0.35, 0.35];

        $canvas->page_text(20, $y, $config->rodape(), $fonte, $tamanho, $cor);

        $canvas->page_text(
            $largura / 2 - 38,
            $y,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $fonte,
            $tamanho,
            $cor
        );

        $canvas->page_text(
            $largura - 88,
            $y,
            now()->format('d/m/Y - H:i'),
            $fonte,
            $tamanho,
            $cor
        );

        return $pdf;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function pdf(string $view, array $dados): DomPdf
    {
        return Pdf::loadView($view, $dados)
            ->setOptions([
                'defaultFont' => 'DejaVu Sans',
                // As imagens institucionais vao embutidas em base64, entao nao
                // precisamos liberar acesso remoto no renderizador.
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
            ]);
    }

    /** Nome do arquivo baixado: escala-agosto-2026.pdf */
    public function nomeArquivo(Escala $escala, string $documento, ?string $sufixo = null): string
    {
        $referencia = Str::slug($escala->primeiroDia()->translatedFormat('F-Y'));

        return trim(implode('-', array_filter([$documento, $referencia, $sufixo])), '-').'.pdf';
    }
}
