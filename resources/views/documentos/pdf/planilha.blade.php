{{--
    Planilha mensal de plantões — documento distribuído às unidades.

    Layout clássico, fiel ao documento que a secretaria já utiliza: uma linha por
    motorista, colunas de placa (P) e lotação (LOT) à esquerda e uma coluna por
    dia do mês, com X no dia de plantão.

    As larguras vão em <colgroup> com porcentagens. Larguras em milímetros
    declaradas nas células são ignoradas pelo dompdf quando a tabela tem muitas
    colunas: ele redistribui o espaço e as primeiras colunas colapsam umas sobre
    as outras.
--}}

@php
    $qtdDias = count($dados->dias);

    // A soma precisa fechar 100%. As cinco primeiras colunas tomam 38% e os dias
    // dividem o restante igualmente.
    $larguraDia = round(62 / max(1, $qtdDias), 4);
@endphp

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $escala->referencia() }}</title>

    @include('documentos.pdf._estilos', ['margem' => '6mm 5mm 11mm 5mm'])

    <style>
        table.planilha {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.planilha th,
        table.planilha td {
            border: 0.5pt solid #000;
            padding: 0.4mm 0.7mm;
            font-size: 6.5pt;
            vertical-align: middle;
            overflow: hidden;
        }

        table.planilha td.dia,
        table.planilha th.dia {
            text-align: center;
            padding: 0.4mm 0;
        }

        th.semana {
            font-size: 5pt;
            font-weight: normal;
            background-color: #f0f0f0;
        }

        th.rotulo {
            font-size: 6pt;
            font-weight: bold;
            background-color: #e4e4e4;
            text-align: center;
        }

        th.numero-dia {
            font-size: 6.5pt;
            font-weight: bold;
            background-color: #e4e4e4;
        }

        /*
            Sábado e domingo.

            A marcação serve só para orientar a leitura ao longo da linha, então
            fica bem discreta no corpo da tabela — o que precisa saltar aos olhos
            é o X do plantão, não a coluna.

            No cabeçalho o tom é um pouco mais forte que o das colunas de dia
            útil, para a distinção continuar visível sobre o cinza já existente.
            As regras vêm depois das de th.semana e th.numero-dia e têm
            especificidade maior, garantindo a precedência.
        */
        td.fim-de-semana {
            background-color: #f7f7f7;
        }

        th.semana.fim-de-semana {
            background-color: #e7e7e7;
        }

        th.numero-dia.fim-de-semana {
            background-color: #d8d8d8;
        }

        td.numero {
            text-align: center;
            font-size: 5.5pt;
            color: #444;
        }

        /* Nome em uma linha só; o texto é truncado na montagem dos dados. */
        td.nome {
            white-space: nowrap;
            font-size: 6.5pt;
        }

        td.fone {
            text-align: center;
            font-size: 6pt;
            white-space: nowrap;
        }

        /*
            Placa e lotação de cada ambulância, agrupadas por rowspan.

            No documento antigo esse texto era impresso girado 90°. O dompdf não
            implementa writing-mode nem rotação de texto, então as colunas foram
            alargadas e o texto vai na horizontal.
        */
        .identificacao {
            text-align: center;
            font-size: 6pt;
            font-weight: bold;
            line-height: 1.15;
        }

        .identificacao .regime {
            display: block;
            font-weight: normal;
            font-size: 5pt;
            color: #333;
        }

        td.marca {
            font-weight: bold;
            font-size: 7.5pt;
            text-align: center;
        }

        tr.vaga-aberta td {
            font-size: 5.5pt;
            font-style: italic;
            background-color: #f7f7f7;
        }

        .assinaturas {
            width: 100%;
            margin-top: 5mm;
            border-collapse: collapse;
        }

        .assinaturas td {
            border: none;
            text-align: center;
            font-size: 6.5pt;
            padding-top: 7mm;
            width: 50%;
        }

        .linha-assinatura {
            border-top: 0.5pt solid #000;
            width: 60mm;
            margin: 0 auto 1mm auto;
        }
    </style>
</head>
<body>

@php $totalPaginas = max(1, count($paginas)); @endphp

@foreach ($paginas as $indice => $pagina)
    @php $numeroLinha = $pagina['primeiro_numero']; @endphp

    {{-- O rodapé com identificação, página e data é escrito no canvas do PDF
         (ver GeradorDeDocumentos::aplicarRodape). --}}

    @include('documentos.pdf._cabecalho', [
        'config' => $config,
        'titulo' => 'Escala Condutores de Ambulância — '.$escala->referencia(),
        'telefones' => true,
    ])

    <table class="planilha">
        <thead>
            {{--
                Dias da semana — e a linha que define a largura das colunas.

                O dompdf não aplica <colgroup> e ignora larguras declaradas em
                classes CSS quando a tabela tem muitas colunas. O que ele respeita
                é o atributo style="width" na primeira linha da tabela, com as
                células separadas (um colspan aqui faria as colunas colapsarem
                umas sobre as outras).
            --}}
            <tr>
                <th class="semana" style="width: 2.2%"></th>   {{-- Nº       --}}
                <th class="semana" style="width: 15.8%"></th>  {{-- CONDUTOR --}}
                <th class="semana" style="width: 7.0%"></th>   {{-- FONE     --}}
                <th class="semana" style="width: 5.6%"></th>   {{-- P        --}}
                <th class="semana" style="width: 7.4%"></th>   {{-- LOT      --}}
                @foreach ($dados->dias as $dia)
                    <th
                        class="dia semana {{ $dia->isWeekend() ? 'fim-de-semana' : '' }}"
                        style="width: {{ $larguraDia }}%"
                    >
                        {{ mb_substr($dia->translatedFormat('D'), 0, 3) }}
                    </th>
                @endforeach
            </tr>

            {{-- Cabeçalho das colunas e números dos dias --}}
            <tr>
                <th class="rotulo">Nº</th>
                <th class="rotulo">CONDUTOR</th>
                <th class="rotulo">FONE</th>
                <th class="rotulo">P</th>
                <th class="rotulo">LOT</th>
                @foreach ($dados->dias as $dia)
                    <th class="dia numero-dia {{ $dia->isWeekend() ? 'fim-de-semana' : '' }}">
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
                        <td class="numero">{{ $numeroLinha++ }}</td>

                        {{-- 26 caracteres é o que a coluna acomoda em 6,5pt --}}
                        <td class="nome">{{ Str::limit($motorista?->nomePlanilha() ?? '', 26, '') }}</td>

                        <td class="fone">{{ $motorista?->telefoneFormatado() }}</td>

                        {{-- Placa e lotação ocupam todas as linhas do bloco --}}
                        @if ($posicaoNoBloco === 0)
                            <td rowspan="{{ $total }}">
                                <div class="identificacao">{{ $bloco['placa'] }}</div>
                            </td>
                            <td rowspan="{{ $total }}">
                                <div class="identificacao">
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
                        <td class="numero"></td>
                        <td colspan="{{ 4 + $qtdDias }}">
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
