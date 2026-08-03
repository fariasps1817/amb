{{--
    Cabecalho institucional dos documentos.

    As imagens vao embutidas em base64 porque o dompdf roda sem acesso remoto:
    ele nao busca URLs http do proprio servidor de forma confiavel.

    Parametros:
      $config    Configuracao institucional
      $titulo    Titulo do documento
      $subtitulo Linha opcional abaixo do titulo
      $telefones Mostra a caixa de telefones a esquerda (planilha)
--}}

@php
    $logoPrefeitura = $config->imagemBase64('logo_prefeitura');
    $logoSecretaria = $config->imagemBase64('logo_secretaria');
    $brasao = $config->imagemBase64('brasao');
    $ambulancia = $config->imagemBase64('imagem_ambulancia');
    $telefones = $telefones ?? false;
@endphp

<table class="cabecalho">
    <tr>
        {{-- Contato do setor, à esquerda --}}
        <td class="cabecalho-lateral">
            @if ($telefones && $config->telefonesFormatados())
                <div class="contato">
                    <strong>{{ Str::limit($config->setor ?: 'Secretaria', 22) }}:</strong><br>
                    {{ $config->telefonesFormatados() }}
                </div>
            @endif
        </td>

        {{-- Identidade e título, ao centro --}}
        <td class="cabecalho-centro">
            <div class="logos">
                @if ($brasao)
                    <img src="{{ $brasao }}" alt="" class="logo">
                @endif
                @if ($logoPrefeitura)
                    <img src="{{ $logoPrefeitura }}" alt="" class="logo">
                @endif
                @if ($logoSecretaria)
                    <img src="{{ $logoSecretaria }}" alt="" class="logo">
                @endif
            </div>

            @unless ($logoPrefeitura || $logoSecretaria || $brasao)
                {{-- Sem imagens cadastradas, o texto institucional assume o topo --}}
                <div class="orgao">
                    @if ($config->prefeitura)
                        <div class="orgao-principal">{{ mb_strtoupper($config->prefeitura) }}</div>
                    @endif
                    @if ($config->secretaria)
                        <div class="orgao-secundario">{{ $config->secretaria }}</div>
                    @endif
                </div>
            @endunless

            <div class="titulo">{{ mb_strtoupper($titulo) }}</div>

            @isset($subtitulo)
                <div class="subtitulo">{{ $subtitulo }}</div>
            @endisset
        </td>

        {{-- Imagem decorativa da ambulância, à direita --}}
        <td class="cabecalho-lateral cabecalho-direita">
            @if ($ambulancia)
                <img src="{{ $ambulancia }}" alt="" class="imagem-ambulancia">
            @endif
        </td>
    </tr>
</table>
