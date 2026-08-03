{{--
    Planilha mensal de plantões — documento distribuído às unidades.

    Uma linha por motorista, uma coluna por dia do mês e um X no dia de plantão.
    As colunas de placa e lotação são estreitas e o texto vai girado 90°, como no
    documento que o setor já utiliza.
--}}

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $escala->referencia() }}</title>

    @include('documentos.pdf._estilos', ['margem' => '6mm 6mm 11mm 6mm'])

    <style>
        /*
            Larguras fixas: com 31 dias, cada milímetro conta.

            A4 paisagem tem 297mm; descontadas as margens sobram 285mm. As cinco
            primeiras colunas somam 97mm, deixando ~6mm por dia — suficiente para
            o X e para o número do dia no cabeçalho.
        */
        .col-numero  { width: 5mm; }
        .col-nome    { width: 40mm; }
        .col-fone    { width: 15mm; }
        .col-placa   { width: 15mm; }
        .col-lotacao { width: 22mm; }

        table.planilha {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.planilha th,
        table.planilha td {
            border: 0.5pt solid #000;
            padding: 0.3mm 0.6mm;
            font-size: 7pt;
            vertical-align: middle;
        }

        table.planilha td.dia,
        table.planilha th.dia {
            text-align: center;
            padding: 0.3mm 0;
        }

        th.semana {
            font-size: 5.5pt;
            font-weight: normal;
            background-color: #f0f0f0;
        }

        th.numero-dia {
            font-size: 7pt;
            font-weight: bold;
        }

        th.fim-de-semana,
        td.fim-de-semana {
            background-color: #ececec;
        }

        /*
            Placa e lotação de cada ambulância.

            No documento antigo esse texto era impresso girado 90°, em colunas de
            poucos milímetros. O dompdf não implementa writing-mode nem rotação de
            texto, então as colunas foram alargadas e o texto vai na horizontal,
            centralizado nas linhas do bloco pelo rowspan. Fica mais legível e
            imprime igual em qualquer visualizador.
        */
        .identificacao-bloco {
            text-align: center;
            font-size: 6.5pt;
            font-weight: bold;
            line-height: 1.2;
        }

        .identificacao-bloco .regime {
            display: block;
            font-weight: normal;
            font-size: 5.5pt;
            color: #333;
        }

        td.marca {
            font-weight: bold;
            font-size: 8pt;
            text-align: center;
        }

        /* O dompdf não recorta com overflow: hidden. Para o nome não invadir a
           coluna vizinha, ele é truncado na montagem dos dados (ver abaixo) e
           impresso em uma linha só. */
        td.nome {
            white-space: nowrap;
            font-size: 6.5pt;
        }

        td.fone {
            text-align: center;
            font-size: 6.5pt;
            white-space: nowrap;
        }

        tr.vaga-aberta td {
            font-size: 6pt;
            font-style: italic;
            background-color: #f7f7f7;
        }

        .assinaturas {
            width: 100%;
            margin-top: 6mm;
            border-collapse: collapse;
        }

        .assinaturas td {
            border: none;
            text-align: center;
            font-size: 7pt;
            padding-top: 8mm;
            width: 50%;
        }

        .linha-assinatura {
            border-top: 0.5pt solid #000;
            width: 65mm;
            margin: 0 auto 1mm auto;
        }
    </style>
</head>
<body>

@php $totalPaginas = max(1, count($paginas)); @endphp

@foreach ($paginas as $indice => $pagina)
    @php $numeroLinha = $pagina['primeiro_numero']; @endphp

    {{-- O rodapé com identificação, página e data é escrito diretamente no
         canvas do PDF (ver GeradorDeDocumentos::aplicarRodape), porque um
         elemento fixo declarado dentro deste laço apareceria repetido. --}}

    @include('documentos.pdf._cabecalho', [
        'config' => $config,
        'titulo' => 'Escala Condutores de Ambulância — '.$escala->referencia(),
        'telefones' => true,
    ])

    <table class="planilha">
        <thead>
            {{-- Dias da semana --}}
            <tr>
                <th class="col-numero semana"></th>
                <th class="col-nome semana"></th>
                <th class="col-fone semana"></th>
                <th class="col-placa semana"></th>
                <th class="col-lotacao semana"></th>
                @foreach ($dados->dias as $dia)
                    <th class="dia semana {{ $dia->isWeekend() ? 'fim-de-semana' : '' }}">
                        {{ mb_substr($dia->translatedFormat('D'), 0, 3) }}
                    </th>
                @endforeach
            </tr>

            {{-- Números dos dias --}}
            <tr>
                <th class="col-numero cinza-claro fina">Nº</th>
                <th class="col-nome cinza-claro">CONDUTOR</th>
                <th class="col-fone cinza-claro">FONE</th>
                <th class="col-placa cinza-claro fina">P</th>
                <th class="col-lotacao cinza-claro fina">LOT</th>
                @foreach ($dados->dias as $dia)
                    <th class="dia numero-dia {{ $dia->isWeekend() ? 'fim-de-semana' : 'cinza-claro' }}">
                        {{ $dia->format('d') }}
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($pagina['blocos'] as $bloco)
                @php
                    $linhas = $bloco['linhas'];
                    $total = count($linhas);
                @endphp

                @foreach ($linhas as $posicaoNoBloco => $linha)
                    @php $motorista = $linha['motorista']; @endphp

                    <tr>
                        <td class="col-numero centro fina">{{ $numeroLinha++ }}</td>

                        {{-- 28 caracteres é o que a coluna de 40mm acomoda em 6,5pt --}}
                        <td class="col-nome nome">{{ Str::limit($motorista?->nomePlanilha() ?? '', 28, '') }}</td>

                        <td class="col-fone fone">{{ $motorista?->telefoneFormatado() }}</td>

                        {{-- Placa e lotação ocupam todas as linhas do bloco --}}
                        @if ($posicaoNoBloco === 0)
                            <td class="col-placa" rowspan="{{ $total }}">
                                <div class="identificacao-bloco">{{ $bloco['placa'] }}</div>
                            </td>
                            <td class="col-lotacao" rowspan="{{ $total }}">
                                <div class="identificacao-bloco">
                                    {{ $bloco['lotacao'] }}
                                    <span class="regime">{{ $bloco['regime'] }}</span>
                                </div>
                            </td>
                        @endif

                        @foreach ($dados->dias as $dia)
                            @php $plantao = $linha['dias'][$dia->toDateString()] ?? null; @endphp

                            <td class="dia marca {{ $dia->isWeekend() ? 'fim-de-semana' : '' }}">
                                {{ $plantao ? 'X' : '' }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                {{-- Vaga não preenchida: registrada no documento para a unidade
                     saber que aquele dia depende de reserva --}}
                @if ($bloco['vagas_livres'] > 0)
                    <tr class="vaga-aberta">
                        <td class="col-numero"></td>
                        <td colspan="{{ 4 + count($dados->dias) }}">
                            {{ $bloco['vagas_livres'] }} vaga(s) sem motorista designado —
                            cobertura pelo plantão de sobreaviso.
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    {{-- Assinaturas apenas na última página --}}
    @if ($indice + 1 === $totalPaginas && ($config->responsavel_setor || $config->secretaria))
        <table class="assinaturas">
            <tr>
                @if ($config->responsavel_setor)
                    <td>
                        <div class="linha-assinatura"></div>
                        {{ mb_strtoupper($config->responsavel_setor) }}<br>
                        <span class="fina">{{ $config->cargo_responsavel ?: 'Coordenação de Ambulâncias' }}</span>
                    </td>
                @endif
                <td>
                    <div class="linha-assinatura"></div>
                    <span class="fina">{{ $config->secretaria ?: 'Secretaria Municipal de Saúde' }}</span>
                </td>
            </tr>
        </table>
    @endif

    @unless ($loop->last)
        <div class="quebra-pagina"></div>
    @endunless
@endforeach

</body>
</html>
