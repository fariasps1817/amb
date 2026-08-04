{{--
    Página final da planilha: condutores fora de escala no mês.

    Fecha o quadro do efetivo no mesmo documento — o RH recebe a escala e a
    relação de quem está fora dela sem precisar de um segundo arquivo — e atende
    as unidades, que precisam saber quem está de sobreaviso para acionar em caso
    de falta.

    Parâmetros:
      $grupos        Coleção vinda de DadosDaPlanilha::foraDeEscala()
      $escala
      $config
      $totalEscalados
--}}

@php
    $totalFora = $grupos->sum(fn ($grupo) => count($grupo['linhas']));
    $numero = 0;
@endphp

<style>
    table.fora {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-top: 3mm;
    }

    table.fora th,
    table.fora td {
        border: 0.5pt solid #555;
        padding: 0.9mm 1.5mm;
        font-size: 7.5pt;
        vertical-align: middle;
        overflow: hidden;
    }

    table.fora thead th {
        background-color: #e4e4e4;
        font-size: 6pt;
        font-weight: bold;
        text-transform: uppercase;
        text-align: center;
        letter-spacing: 0.2pt;
    }

    /* Faixa de cada situação, no mesmo padrão visual do layout agrupado. */
    td.grupo {
        background-color: #dce9e7;
        padding: 1mm 1.5mm;
        font-size: 7.5pt;
        font-weight: bold;
    }

    td.grupo .contagem {
        font-weight: normal;
        color: #2f5b58;
    }

    /* Sobreaviso e apoio continuam à disposição do setor: marcação mais viva
       para a unidade localizar rápido quem pode acionar. */
    td.grupo.disponivel {
        background-color: #cfe3e0;
    }

    td.grupo.afastado {
        background-color: #eeeeee;
    }

    .f-num   { width: 7%;  text-align: center; font-size: 6.5pt; color: #444; }
    .f-nome  { width: 34%; }
    .f-vinc  { width: 12%; text-align: center; }
    .f-fone  { width: 15%; text-align: center; white-space: nowrap; }
    .f-per   { width: 14%; text-align: center; font-size: 7pt; }
    .f-obs   { width: 18%; font-size: 6.5pt; font-style: italic; }

    table.resumo-efetivo {
        width: 100%;
        border-collapse: collapse;
        margin-top: 4mm;
    }

    table.resumo-efetivo td {
        border: 0.5pt solid #666;
        padding: 1.4mm;
        font-size: 6.5pt;
        text-align: center;
        width: 25%;
    }

    table.resumo-efetivo .valor {
        font-size: 10pt;
        font-weight: bold;
    }
</style>

@include('documentos.pdf._cabecalho', [
    'config' => $config,
    'titulo' => 'Condutores Fora de Escala — '.$escala->referencia(),
    'subtitulo' => 'Sobreaviso, apoio e afastamentos',
])

@if ($totalFora === 0)
    <p style="margin-top: 15mm; text-align: center; font-size: 9pt;">
        Todos os condutores ativos estão escalados neste mês.
    </p>
@else
    <table class="fora">
        <thead>
            <tr>
                <th class="f-num">Nº</th>
                <th class="f-nome">Condutor</th>
                <th class="f-vinc">Vínculo</th>
                <th class="f-fone">Fone</th>
                <th class="f-per">Período</th>
                <th class="f-obs">Observação</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($grupos as $grupo)
                <tr>
                    <td class="grupo {{ $grupo['disponivel'] ? 'disponivel' : 'afastado' }}" colspan="6">
                        {{ $grupo['rotulo'] }}
                        <span class="contagem">
                            — {{ count($grupo['linhas']) }}
                            {{ count($grupo['linhas']) === 1 ? 'condutor' : 'condutores' }}
                        </span>
                    </td>
                </tr>

                @foreach ($grupo['linhas'] as $linha)
                    <tr>
                        <td class="f-num">{{ ++$numero }}</td>
                        <td class="f-nome">{{ $linha['motorista']->nomeDocumento() }}</td>
                        <td class="f-vinc">{{ $linha['vinculo'] }}</td>
                        <td class="f-fone">{{ $linha['telefone'] }}</td>
                        <td class="f-per">{{ $linha['periodo'] }}</td>
                        <td class="f-obs">{{ $linha['observacao'] }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    {{-- Fechamento do efetivo: escalados + fora = total ativo --}}
    <table class="resumo-efetivo">
        <tr>
            <td>
                Escalados em ambulância<br>
                <span class="valor">{{ $totalEscalados }}</span>
            </td>
            <td>
                Fora de escala<br>
                <span class="valor">{{ $totalFora }}</span>
            </td>
            <td>
                À disposição (sobreaviso e apoio)<br>
                <span class="valor">
                    {{ $grupos->filter(fn ($g) => $g['disponivel'])->sum(fn ($g) => count($g['linhas'])) }}
                </span>
            </td>
            <td>
                Total do efetivo<br>
                <span class="valor">{{ $totalEscalados + $totalFora }}</span>
            </td>
        </tr>
    </table>
@endif

@if ($config->responsavel_setor)
    <div style="margin-top: 12mm; text-align: center; font-size: 7.5pt;">
        <div style="border-top: 0.5pt solid #000; width: 70mm; margin: 0 auto 1mm auto;"></div>
        {{ mb_strtoupper($config->responsavel_setor) }}<br>
        <span class="fina">{{ $config->cargo_responsavel ?: 'Coordenação de Ambulâncias' }}</span>
    </div>
@endif
