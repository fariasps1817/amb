{{--
    Planilha mensal de plantões — variante agrupada.

    Em vez das colunas estreitas de placa (P) e lotação (LOT) à esquerda, cada
    ambulância ganha uma faixa de cabeçalho com placa, lotação, regime e unidade.

    Ganha-se toda a largura dessas duas colunas para o nome e o telefone, e a
    identificação da ambulância fica muito mais destacada — é o mesmo formato
    usado na tela da escala.
--}}

@php
    $qtdDias = count($dados->dias);

    // As três primeiras colunas tomam 30%; os dias dividem os 70% restantes.
    $larguraDia = round(70 / max(1, $qtdDias), 4);
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
            padding: 0.5mm 0.8mm;
            font-size: 7pt;
            vertical-align: middle;
            overflow: hidden;
        }

        table.planilha td.dia,
        table.planilha th.dia {
            text-align: center;
            padding: 0.5mm 0;
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

            Marcação discreta no corpo da tabela: ela orienta a leitura ao longo
            da linha, mas quem precisa saltar aos olhos é o X do plantão.

            No cabeçalho o tom é um pouco mais forte que o das colunas de dia
            útil, para a distinção continuar visível sobre o cinza já existente.
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

        /*
            Faixa de identificação da ambulância, no lugar das colunas P e LOT.

            Ordem de leitura: sigla da lotação, nome da unidade e placa —
            GUANACES · Posto de Saúde de Guanacés · THQ4J09.

            A sigla e a placa vão em destaque por serem os identificadores que o
            leitor procura; o nome da unidade fica discreto, apenas para
            confirmar de que lugar se trata.
        */
        td.faixa {
            background-color: #dce9e7;
            padding: 0.9mm 1.5mm;
            font-size: 7pt;
        }

        td.faixa .sigla {
            font-weight: bold;
            font-size: 8pt;
            letter-spacing: 0.2pt;
        }

        td.faixa .unidade {
            font-weight: normal;
            font-size: 6.5pt;
            color: #2f5b58;
        }

        td.faixa .placa {
            font-weight: bold;
            font-size: 7.5pt;
            letter-spacing: 0.3pt;
        }

        td.faixa .separador {
            color: #6b8f8c;
        }

        td.numero {
            text-align: center;
            font-size: 5.5pt;
            color: #444;
        }

        td.nome {
            white-space: nowrap;
            font-size: 7pt;
        }

        td.fone {
            text-align: center;
            font-size: 6.5pt;
            white-space: nowrap;
        }

        td.marca {
            font-weight: bold;
            font-size: 7.5pt;
            text-align: center;
        }

        tr.vaga-aberta td {
            font-size: 5.5pt;
            font-style: italic;
            background-color: #fdf3f3;
            color: #8b2b2b;
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
                é o atributo style="width" na primeira linha, com as células
                separadas (um colspan aqui faria as colunas colapsarem).
            --}}
            <tr>
                <th class="semana" style="width: 2.5%"></th>   {{-- Nº       --}}
                <th class="semana" style="width: 19.5%"></th>  {{-- CONDUTOR --}}
                <th class="semana" style="width: 8.0%"></th>   {{-- FONE     --}}
                @foreach ($dados->dias as $dia)
                    <th
                        class="dia semana {{ $dia->isWeekend() ? 'fim-de-semana' : '' }}"
                        style="width: {{ $larguraDia }}%"
                    >
                        {{ mb_substr($dia->translatedFormat('D'), 0, 3) }}
                    </th>
                @endforeach
            </tr>

            <tr>
                <th class="rotulo">Nº</th>
                <th class="rotulo">CONDUTOR</th>
                <th class="rotulo">FONE</th>
                @foreach ($dados->dias as $dia)
                    <th class="dia numero-dia {{ $dia->isWeekend() ? 'fim-de-semana' : '' }}">
                        {{ $dia->format('d') }}
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($pagina['blocos'] as $bloco)
                {{-- Faixa da ambulância --}}
                <tr>
                    <td class="faixa" colspan="{{ 3 + $qtdDias }}">
                        <span class="sigla">{{ $bloco['lotacao'] }}</span>

                        @if ($bloco['unidade'])
                            <span class="separador"> · </span>
                            <span class="unidade">{{ $bloco['unidade'] }}</span>
                        @endif

                        <span class="separador"> · </span>
                        <span class="placa">{{ $bloco['placa'] }}</span>
                    </td>
                </tr>

                @foreach ($bloco['linhas'] as $linha)
                    @php $motorista = $linha['motorista']; @endphp

                    <tr>
                        <td class="numero">{{ $numeroLinha++ }}</td>

                        {{-- A coluna é bem mais larga que no layout clássico --}}
                        <td class="nome">{{ Str::limit($motorista?->nomePlanilha() ?? '', 34, '') }}</td>

                        <td class="fone">{{ $motorista?->telefoneFormatado() }}</td>

                        @foreach ($dados->dias as $dia)
                            @php $plantao = $linha['dias'][$dia->toDateString()] ?? null; @endphp

                            <td class="dia marca {{ $dia->isWeekend() ? 'fim-de-semana' : '' }}">
                                {{ $plantao ? 'X' : '' }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                @if ($bloco['vagas_livres'] > 0)
                    <tr class="vaga-aberta">
                        <td class="numero"></td>
                        <td colspan="{{ 2 + $qtdDias }}">
                            {{ $bloco['vagas_livres'] }} vaga(s) sem motorista designado —
                            cobertura pelo plantão de sobreaviso.
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

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
