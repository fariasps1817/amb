{{--
    Relação do cadastro de motoristas, no recorte exibido na listagem.

    Diferente dos outros PDFs, este não pertence a uma escala: é o cadastro em
    si. Por isso traz os filtros aplicados logo abaixo do título — impressa e
    passada adiante, a folha precisa dizer por que aqueles motoristas estão ali.

    As colunas são escolhidas na tela e chegam em $colunas, que também decide a
    largura de cada uma e se a folha sai em pé ou deitada.
--}}

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Motoristas</title>

    @include('documentos.pdf._estilos', ['margem' => $colunas->margem()])

    @php $larguras = $colunas->larguras(); @endphp

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
            padding: 1mm 1.2mm;
            font-size: {{ $colunas->fonte() }}pt;
            vertical-align: middle;
        }

        table.lista thead th {
            background-color: #e8e8e8;
            font-weight: bold;
            text-align: center;
            font-size: {{ $colunas->fonteDoCabecalho() }}pt;
            text-transform: uppercase;
        }

        /*
            As larguras NÃO ficam aqui: o dompdf ignora width declarado em
            classe e reparte o espaço igualmente. Elas vão em style="width" nos
            <th>. Aqui fica só o alinhamento.
        */
        .centralizada {
            text-align: center;
        }

        @if ($colunas->temCampoLivre())
            /* Com um campo em branco na folha, a linha precisa caber a caneta. */
            table.lista tbody td {
                padding-top: 2.6mm;
                padding-bottom: 2.6mm;
            }
        @endif

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
            <th class="centralizada" style="width: {{ $larguras['numero'] }}%">Nº</th>

            @foreach ($colunas->chaves() as $chave)
                <th
                    class="{{ $colunas->centralizada($chave) ? 'centralizada' : '' }}"
                    style="width: {{ $larguras[$chave] }}%"
                >{{ $colunas->rotulo($chave) }}</th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @forelse ($motoristas as $motorista)
            <tr class="{{ $loop->index % 2 === 1 ? 'alternada' : '' }}">
                <td class="centralizada">{{ $loop->iteration }}</td>

                @foreach ($colunas->chaves() as $chave)
                    @php
                        // Contrato encerrado e CNH vencida impedem a escalação:
                        // marcados em negrito com asterisco, porque a impressora
                        // do setor é monocromática.
                        $pendente = match ($chave) {
                            'vinculo' => $motorista->contratoEncerrado(),
                            'cnh' => $motorista->cnhVencida(),
                            default => false,
                        };

                        $classes = implode(' ', array_filter([
                            $colunas->centralizada($chave) ? 'centralizada' : null,
                            $pendente ? 'pendencia' : null,
                        ]));
                    @endphp

                    <td class="{{ $classes }}">@switch($chave)
                        @case('servidor'){{ $motorista->nomeDocumento() }}@break

                        @case('nome_curto'){{ $motorista->nome_curto ?: '—' }}@break

                        @case('telefone')
                            @if ($motorista->telefone_1){{ $motorista->telefoneFormatado() }}@else<span class="pendencia">sem telefone</span>@endif
                        @break

                        {{-- Sem a data de término: o contrato encerrado sai
                             marcado, que é o que interessa na conferência. --}}
                        @case('vinculo'){{ $motorista->vinculo->rotuloDoServidor() }}@break

                        {{-- Categoria e validade em uma linha: "E (06/27)". O
                             dia não muda a decisão de escalar; o mês, sim. --}}
                        @case('cnh'){{ $motorista->cnh_categoria ?: '—' }}{{ $motorista->cnh_validade ? ' ('.$motorista->cnh_validade->format('m/y').')' : '' }}@break

                        @case('status'){{ $motorista->status->rotulo() }}@break

                        @case('cpf'){{ $motorista->cpf ? $motorista->cpfFormatado() : '—' }}@break

                        @case('nascimento'){{ $motorista->data_nascimento?->format('d/m/Y') ?: '—' }}@break

                        {{-- Deliberadamente vazia: é espaço para escrever à mão
                             ou assinar depois de impressa. --}}
                        @case('observacao')@break
                    @endswitch</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ 1 + count($colunas->chaves()) }}" class="centro">
                    Nenhum motorista encontrado com os filtros aplicados.
                </td>
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
