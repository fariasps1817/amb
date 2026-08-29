{{--
    Relação do cadastro de motoristas, no recorte exibido na listagem.

    Diferente dos outros PDFs, este não pertence a uma escala: é o cadastro em
    si. Por isso traz os filtros aplicados logo abaixo do título — impressa e
    passada adiante, a folha precisa dizer por que aqueles motoristas estão ali.
--}}

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Motoristas</title>

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
            As larguras NÃO ficam aqui: o dompdf ignora width declarado em
            classe e reparte o espaço igualmente. Elas vão em style="width" nos
            <th>. Aqui fica só o alinhamento.
        */
        .c-numero   { text-align: center; }
        .c-telefone { text-align: center; }
        .c-vinculo  { text-align: center; }
        .c-cnh      { text-align: center; }

        .nome-curto {
            font-size: 6pt;
            color: #555;
        }

        /* Pendências saltam à vista na folha impressa, que é onde a cobrança
           acontece. Sem cor, porque a impressora do setor é monocromática. */
        .pendencia {
            font-weight: bold;
        }

        .pendencia:after {
            content: ' *';
        }

        tr.alternada td {
            background-color: #f7f7f7;
        }

        .filtros {
            margin-top: 2mm;
            font-size: 7pt;
            font-style: italic;
            text-align: center;
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

        .legenda {
            margin-top: 2mm;
            font-size: 6.5pt;
            font-style: italic;
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
    'titulo' => 'Relação de Motoristas',
    'subtitulo' => 'Cadastro do setor de coordenação de ambulâncias',
])

@if ($filtros)
    <div class="filtros">Filtros aplicados — {{ implode(' · ', $filtros) }}</div>
@endif

<table class="lista">
    {{-- As larguras vão inline nesta linha, que é onde o dompdf as lê. --}}
    <thead>
        <tr>
            <th class="c-numero" style="width: 5.0%">Nº</th>
            <th class="c-servidor" style="width: 41.0%">Servidor</th>
            <th class="c-telefone" style="width: 17.0%">Telefone</th>
            <th class="c-vinculo" style="width: 17.0%">Vínculo</th>
            <th class="c-cnh" style="width: 20.0%">CNH (val)</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($motoristas as $motorista)
            <tr class="{{ $loop->index % 2 === 1 ? 'alternada' : '' }}">
                <td class="c-numero">{{ $loop->iteration }}</td>

                <td class="c-servidor">
                    {{ $motorista->nomeDocumento() }}
                    @if ($motorista->nome_curto && $motorista->nome_curto !== $motorista->nome_completo)
                        <div class="nome-curto">{{ $motorista->nome_curto }}</div>
                    @endif
                </td>

                <td class="c-telefone">
                    @if ($motorista->telefone_1)
                        {{ $motorista->telefoneFormatado() }}
                    @else
                        <span class="pendencia">sem telefone</span>
                    @endif
                </td>

                {{-- Sem a data de término: o contrato encerrado é marcado com
                     asterisco, que é o que interessa na conferência. --}}
                <td class="c-vinculo {{ $motorista->contratoEncerrado() ? 'pendencia' : '' }}">
                    {{ $motorista->vinculo->rotuloDoServidor() }}
                </td>

                {{-- Categoria e validade em uma linha só: "E (06/27)". O dia
                     não muda a decisão de escalar; o mês do vencimento, sim. --}}
                <td class="c-cnh {{ $motorista->cnhVencida() ? 'pendencia' : '' }}">
                    {{ $motorista->cnh_categoria ?: '—' }}{{ $motorista->cnh_validade ? ' ('.$motorista->cnh_validade->format('m/y').')' : '' }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="centro">Nenhum motorista encontrado com os filtros aplicados.</td>
            </tr>
        @endforelse
    </tbody>
</table>

@php
    $ativos = $motoristas->filter(fn ($m) => $m->ativo())->count();
    $comPendencia = $motoristas
        ->filter(fn ($m) => $m->cnhVencida() || $m->contratoEncerrado() || blank($m->telefone_1))
        ->count();
@endphp

<table class="resumo">
    <tr>
        <td>
            Motoristas relacionados<br>
            <span class="valor">{{ $motoristas->count() }}</span>
        </td>
        <td>
            Ativos<br>
            <span class="valor">{{ $ativos }}</span>
        </td>
        <td>
            Inativos<br>
            <span class="valor">{{ $motoristas->count() - $ativos }}</span>
        </td>
        <td>
            Com pendência<br>
            <span class="valor">{{ $comPendencia }}</span>
        </td>
    </tr>
</table>

@if ($comPendencia)
    <div class="legenda">
        * CNH vencida, contrato encerrado ou ausência de telefone — impedem a escalação.
    </div>
@endif

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
