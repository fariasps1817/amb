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

        /*
            Altura das linhas calibrada para as 31 linhas de dia mais o cabeçalho
            preencherem a folha A4 sem passar para uma segunda página.

            Medido: com este padding, 6,5mm é o limite exato. Ficamos em 6,3mm
            para sobrar margem quando alguma linha traz observação e quebra em
            duas. Aumentar daqui empurra a última linha para a página seguinte.
        */
        table.frequencia th,
        table.frequencia td {
            border: 0.5pt solid #555;
            padding: 0.35mm 1mm;
            font-size: 7.5pt;
            line-height: 1.1;
            height: 6.3mm;
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

        /*
            Alinhamento e tipografia das colunas. As larguras vão em
            style="width" no cabeçalho da tabela: o dompdf ignora width
            declarado em classe e distribui o espaço igualmente.
        */
        .f-data {
            text-align: center;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
        }

        .f-hora {
            text-align: center;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8pt;
            color: #333;
        }

        /*
            Observação do plantão — anotações curtas: "troca com Fulano",
            "atraso de 2h". Fica em uma linha só (o texto é truncado na
            montagem) para a altura da folha ser previsível: se cada linha
            puder crescer, a última acaba empurrada para uma segunda página.
        */
        .f-observacao {
            font-size: 6.5pt;
            white-space: nowrap;
        }

        /* Linha que só carrega as larguras; não deve ocupar espaço nem imprimir. */
        table.frequencia tr.larguras td {
            height: 0;
            padding: 0;
            border: none;
            line-height: 0;
            font-size: 0;
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

        .numero-folha {
            margin-top: 2mm;
            text-align: right;
            font-size: 11pt;
            font-weight: bold;
        }

        /* Aviso na folha de sobreaviso e apoio, cujos dias não são definidos. */
        .nota-sobreaviso {
            font-size: 7pt;
            font-style: italic;
            color: #7a4a00;
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
                {{-- Sobreaviso e apoio não seguem regime fixo de plantão --}}
                <div class="valor-campo">{{ $folha['regime'] ?: '—' }}</div>
            </td>
            <td style="width: 22%;">
                <div class="etiqueta">Referência</div>
                <div class="valor-campo">{{ $escala->referenciaCurta() }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <span style="font-size: 8.5pt;">
                    <span class="etiqueta">Lotação:</span>
                    <strong>{{ $folha['lotacao'] }}</strong>
                    <span style="color: #666;">&nbsp;–&nbsp;</span>
                    <span class="etiqueta">Vínculo:</span>
                    <strong>{{ $folha['vinculo'] }}</strong>

                    @if ($folha['todos_os_dias_em_branco'])
                        <span class="nota-sobreaviso">
                            — assinar somente os dias em que houver plantão
                        </span>
                    @endif
                </span>
            </td>
        </tr>
    </table>

    {{-- Dias do mês --}}
    <table class="frequencia">
        <thead>
            {{--
                Linha sem altura que define as larguras das colunas.

                O cabeçalho visível agrupa Entrada e Saída com colspan, e o
                dompdf não deriva larguras individuais de uma linha com colspan —
                acabaria distribuindo o espaço igualmente entre as seis colunas,
                espremendo o campo de assinatura. Esta linha existe só para ele
                ler as larguras; não imprime nada.
            --}}
            <tr class="larguras">
                <td style="width: 9%"></td>    {{-- data de entrada  --}}
                <td style="width: 8%"></td>    {{-- hora de entrada  --}}
                <td style="width: 9%"></td>    {{-- data de saída    --}}
                <td style="width: 8%"></td>    {{-- hora de saída    --}}
                <td style="width: 44%"></td>   {{-- assinatura       --}}
                <td style="width: 22%"></td>   {{-- observação       --}}
            </tr>

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

                Sobreaviso e apoio não têm dias definidos na escala, então a folha
                sai inteira em branco — o motorista assina os dias em que foi
                acionado e o coordenador aponta o restante.
            --}}
            @foreach ($folha['linhas'] as $linha)
                @php $emBranco = $folha['todos_os_dias_em_branco'] || $linha['plantao']; @endphp

                <tr class="{{ $emBranco ? '' : 'linha-folga' }}">
                    <td class="f-data">{{ $linha['entrada_data'] }}</td>
                    <td class="f-hora">{{ $linha['entrada_hora'] }}</td>
                    <td class="f-data">{{ $linha['saida_data'] }}</td>
                    <td class="f-hora">{{ $linha['saida_hora'] }}</td>

                    <td class="f-assinatura">
                        @unless ($emBranco)
                            <div class="folga">* * * * Folga * * * *</div>
                        @endunless
                    </td>

                    {{-- 32 caracteres é o que a coluna acomoda em 6,5pt --}}
                    <td class="f-observacao">{{ Str::limit((string) $linha['observacao'], 32) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Só o número da folha: identificação do setor, local e data já vêm no
         rodapé de todas as páginas, escrito no canvas do PDF. --}}
    <div class="numero-folha">{{ $loop->iteration }}</div>

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
