{{--
    Lista mensal de ocorrências — todo o efetivo do mês em ordem alfabética.

    Contempla escalados, reservas e afastados, com lotação, vínculo, quantidade
    de plantões previstos e a observação do mês.
--}}

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Ocorrências {{ $escala->referencia() }}</title>

    @include('documentos.pdf._estilos', ['margem' => '10mm 10mm 14mm 10mm'])

    <style>
        table.lista {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 3mm;
        }

        table.lista th,
        table.lista td {
            border: 0.5pt solid #444;
            padding: 0.9mm 1.2mm;
            font-size: 7pt;
            vertical-align: middle;
        }

        table.lista thead th {
            background-color: #e8e8e8;
            font-weight: bold;
            text-align: center;
            font-size: 6.5pt;
            text-transform: uppercase;
        }

        /*
            Alinhamento das colunas. As larguras NÃO ficam aqui: o dompdf ignora
            width declarado em classe e distribui o espaço igualmente entre as
            colunas. Elas vão em style="width" nos <th> do cabeçalho.
        */
        .c-numero    { text-align: center; }
        .c-vinculo   { text-align: center; }
        .c-plantoes  { text-align: center; }
        .c-ocorrencia{ font-style: italic; font-size: 6.5pt; }

        tr.alternada td {
            background-color: #f7f7f7;
        }

        .resumo {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4mm;
        }

        .resumo td {
            border: 0.5pt solid #666;
            padding: 1.2mm;
            font-size: 6.5pt;
            text-align: center;
        }

        .resumo .valor {
            font-size: 9pt;
            font-weight: bold;
        }

        .local-data {
            margin-top: 6mm;
            text-align: right;
            font-size: 7.5pt;
            font-style: italic;
        }

        .assinatura {
            margin-top: 12mm;
            text-align: center;
            font-size: 7.5pt;
        }

        .linha-assinatura {
            border-top: 0.5pt solid #000;
            width: 75mm;
            margin: 0 auto 1mm auto;
        }
    </style>
</head>
<body>

@include('documentos.pdf._cabecalho', [
    'config' => $config,
    'titulo' => 'Ocorrências Mensal — '.$escala->referencia(),
    'subtitulo' => 'Motoristas de Ambulância',
])

<table class="lista">
    {{--
        As larguras vão inline nesta linha, que é onde o dompdf as lê. A soma
        fecha 100% da área útil (A4 retrato menos as margens, ~190mm).
    --}}
    <thead>
        <tr>
            <th class="c-numero" style="width: 3.5%">Nº</th>
            <th class="c-servidor" style="width: 35.5%">Servidor</th>
            <th class="c-lotacao" style="width: 19.0%">Lotação</th>
            <th class="c-vinculo" style="width: 10.5%">Vínculo</th>
            <th class="c-plantoes" style="width: 8.5%">Plantões</th>
            <th class="c-ocorrencia" style="width: 23.0%">Ocorrência</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($linhas as $linha)
            <tr class="{{ $loop->index % 2 === 1 ? 'alternada' : '' }}">
                <td class="c-numero">{{ $linha['numero'] }}</td>
                <td class="c-servidor">{{ $linha['nome'] }}</td>
                <td class="c-lotacao">{{ $linha['lotacao'] }}</td>
                <td class="c-vinculo">{{ $linha['vinculo'] }}</td>
                <td class="c-plantoes">{{ $linha['plantoes'] }}</td>
                <td class="c-ocorrencia">{{ $linha['ocorrencia'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="centro">Nenhum motorista lançado nesta escala.</td>
            </tr>
        @endforelse
    </tbody>
</table>

{{-- Fechamento numérico do mês, para conferência rápida --}}
@php
    $totalPlantoes = $linhas->sum(fn ($l) => is_numeric($l['plantoes']) ? (int) $l['plantoes'] : 0);
    $comOcorrencia = $linhas->filter(fn ($l) => filled($l['ocorrencia']))->count();
@endphp

<table class="resumo">
    <tr>
        <td>
            Servidores relacionados<br>
            <span class="valor">{{ $linhas->count() }}</span>
        </td>
        <td>
            Total de plantões no mês<br>
            <span class="valor">{{ $totalPlantoes }}</span>
        </td>
        <td>
            Com ocorrência registrada<br>
            <span class="valor">{{ $comOcorrencia }}</span>
        </td>
        <td>
            Dias no mês<br>
            <span class="valor">{{ $escala->diasNoMes() }}</span>
        </td>
    </tr>
</table>

<div class="local-data">{{ $config->localData() }}</div>

@if ($config->responsavel_setor)
    <div class="assinatura">
        <div class="linha-assinatura"></div>
        {{ mb_strtoupper($config->responsavel_setor) }}<br>
        <span class="fina">{{ $config->cargo_responsavel ?: 'Coordenação de Ambulâncias' }}</span>
    </div>
@endif

</body>
</html>
