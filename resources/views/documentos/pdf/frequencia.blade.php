{{--
    Folha de frequência mensal — uma página por motorista, para assinatura.

    Todos os dias do mês são listados. Nos dias de plantão a linha de assinatura
    fica em branco; nos dias de descanso é impresso "Folga", para que ninguém
    assine um dia que não trabalhou.
--}}

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Frequência {{ $escala->referencia() }}</title>

    @include('documentos.pdf._estilos', ['margem' => '6mm 12mm 9mm 12mm'])

    <style>
        /* A folha precisa caber em uma unica pagina A4: sao ate 31 linhas de dia
           mais cabecalho, identificacao e rodape. As alturas de linha, o logo e
           os espacamentos internos foram dimensionados para esse limite — mexer
           neles pode empurrar as ultimas linhas para uma segunda pagina. */
        .logo {
            height: 9mm;
        }

        .imagem-ambulancia {
            height: 9mm;
        }

        .titulo {
            font-size: 10pt;
        }
        table.identificacao {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5mm;
        }

        table.identificacao td {
            border: 0.5pt solid #000;
            padding: 0.9mm 2mm;
            vertical-align: top;
        }

        .etiqueta {
            font-size: 5.5pt;
            text-transform: uppercase;
            letter-spacing: 0.3pt;
            color: #333;
        }

        .valor-campo {
            font-size: 9.5pt;
            font-weight: bold;
            font-family: 'DejaVu Sans Mono', monospace;
        }

        table.frequencia {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 2mm;
        }

        table.frequencia th,
        table.frequencia td {
            border: 0.5pt solid #555;
            padding: 0.35mm 1mm;
            font-size: 7.5pt;
            line-height: 1.1;
            height: 5mm;
            vertical-align: middle;
        }

        table.frequencia thead th {
            background-color: #ececec;
            font-size: 6pt;
            font-weight: bold;
            text-transform: uppercase;
            text-align: center;
            letter-spacing: 0.2pt;
        }

        .f-data {
            width: 17mm;
            text-align: center;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
        }

        .f-hora {
            width: 15mm;
            text-align: center;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
            color: #333;
        }

        .f-assinatura {
            width: 62mm;
        }

        .f-observacao {
            width: 40mm;
            font-size: 6.5pt;
        }

        /* Dia de descanso: impresso em cinza e itálico, sem espaço de assinatura.
           O texto precisa caber em uma única linha, senão a folha ganha altura e
           transborda para a segunda página. */
        .folga {
            text-align: center;
            font-style: italic;
            font-size: 6.5pt;
            color: #999;
            letter-spacing: 0.4pt;
            white-space: nowrap;
        }

        tr.linha-folga td {
            background-color: #fbfbfb;
        }

        .rodape-folha {
            margin-top: 2.5mm;
        }

        .rodape-folha table {
            width: 100%;
            border-collapse: collapse;
        }

        .rodape-folha td {
            border: none;
            font-size: 6.5pt;
            padding: 0;
            vertical-align: bottom;
        }

        .numero-folha {
            text-align: right;
            font-size: 12pt;
            font-weight: bold;
        }
    </style>
</head>
<body>

@forelse ($folhas as $folha)
    @php $motorista = $folha['motorista']; @endphp

    @include('documentos.pdf._cabecalho', [
        'config' => $config,
        'titulo' => 'Frequência Mensal',
    ])

    {{-- Identificação: nome, escala e mês de referência --}}
    <table class="identificacao">
        <tr>
            <td style="width: 58%;">
                <div class="etiqueta">Nome do motorista</div>
                <div class="valor-campo">{{ $motorista->nomeDocumento() }}</div>
            </td>
            <td style="width: 20%;">
                <div class="etiqueta">Escala</div>
                <div class="valor-campo">{{ $folha['regime'] }}</div>
            </td>
            <td style="width: 22%;">
                <div class="etiqueta">Referência</div>
                <div class="valor-campo">{{ $escala->referenciaCurta() }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <span class="etiqueta">Lotação</span>
                <span style="font-size: 8pt;">
                    {{ $folha['lotacao'] }}
                    @if ($folha['placa'])
                        · Ambulância {{ $folha['placa'] }}
                    @endif
                    · {{ $folha['total_plantoes'] }} plantão(ões) previsto(s) no mês
                </span>
            </td>
        </tr>
    </table>

    {{-- Dias do mês --}}
    <table class="frequencia">
        <thead>
            <tr>
                <th colspan="2">Entrada</th>
                <th colspan="2">Saída</th>
                <th>Assinatura</th>
                <th>Observação</th>
            </tr>
        </thead>
        <tbody>
            {{--
                As colunas de entrada e saída são preenchidas em todos os dias,
                como no documento que o setor já usa. O que distingue o dia de
                plantão é a coluna de assinatura: em branco quando há plantão,
                marcada como folga quando não há.
            --}}
            @foreach ($folha['linhas'] as $linha)
                <tr class="{{ $linha['plantao'] ? '' : 'linha-folga' }}">
                    <td class="f-data">{{ $linha['entrada_data'] }}</td>
                    <td class="f-hora">{{ $linha['entrada_hora'] }}</td>
                    <td class="f-data">{{ $linha['saida_data'] }}</td>
                    <td class="f-hora">{{ $linha['saida_hora'] }}</td>

                    <td class="f-assinatura">
                        @unless ($linha['plantao'])
                            <div class="folga">* * * * Folga * * * *</div>
                        @endunless
                    </td>

                    <td class="f-observacao">{{ $linha['observacao'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rodape-folha">
        <table>
            <tr>
                <td>
                    {{ $config->rodape() }}<br>
                    {{ $config->localData() }}
                </td>
                <td class="numero-folha">{{ $loop->iteration }}</td>
            </tr>
        </table>
    </div>

    @unless ($loop->last)
        <div class="quebra-pagina"></div>
    @endunless
@empty
    @include('documentos.pdf._cabecalho', [
        'config' => $config,
        'titulo' => 'Frequência Mensal — '.$escala->referencia(),
    ])

    <p style="margin-top: 20mm; text-align: center; font-size: 9pt;">
        Nenhum motorista escalado nesta escala. Gere os plantões antes de emitir as folhas de frequência.
    </p>
@endforelse

</body>
</html>
