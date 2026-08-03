{{--
    Estilos comuns dos documentos em PDF.

    O dompdf suporta um subconjunto de CSS 2.1: sem flexbox, sem grid e sem
    variaveis. Por isso o layout usa tabelas e medidas absolutas em milimetros.
--}}

<style>
    @page {
        margin: {{ $margem ?? '8mm 8mm 12mm 8mm' }};
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 8pt;
        color: #000;
        margin: 0;
        padding: 0;
    }

    /* ----------------------------------------------------------------
       Cabeçalho
       ---------------------------------------------------------------- */

    table.cabecalho {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2mm;
    }

    table.cabecalho td {
        vertical-align: middle;
        padding: 0;
    }

    .cabecalho-lateral {
        width: 22%;
    }

    .cabecalho-centro {
        width: 56%;
        text-align: center;
    }

    .cabecalho-direita {
        text-align: right;
    }

    .contato {
        font-size: 7pt;
        line-height: 1.35;
    }

    .logos {
        margin-bottom: 1mm;
    }

    .logo {
        height: 12mm;
        max-width: 45mm;
        vertical-align: middle;
        margin: 0 2mm;
    }

    .imagem-ambulancia {
        height: 13mm;
        max-width: 40mm;
    }

    .orgao {
        line-height: 1.25;
        margin-bottom: 1mm;
    }

    .orgao-principal {
        font-size: 9pt;
        font-weight: bold;
    }

    .orgao-secundario {
        font-size: 8pt;
    }

    .titulo {
        font-size: 11pt;
        font-weight: bold;
        text-transform: uppercase;
        line-height: 1.2;
    }

    .subtitulo {
        font-size: 8.5pt;
        margin-top: 0.5mm;
    }

    /* ----------------------------------------------------------------
       Tabelas de dados
       ---------------------------------------------------------------- */

    table.dados {
        width: 100%;
        border-collapse: collapse;
    }

    table.dados th,
    table.dados td {
        border: 0.5pt solid #000;
        padding: 0.6mm 1mm;
        vertical-align: middle;
    }

    table.dados thead th {
        font-weight: bold;
        text-align: center;
    }

    .centro {
        text-align: center;
    }

    .direita {
        text-align: right;
    }

    .esquerda {
        text-align: left;
    }

    .negrito {
        font-weight: bold;
    }

    .cinza {
        background-color: #e8e8e8;
    }

    .cinza-claro {
        background-color: #f4f4f4;
    }

    .fina {
        font-size: 6.5pt;
    }

    /* ----------------------------------------------------------------
       Rodapé fixo em todas as páginas
       ---------------------------------------------------------------- */

    .rodape {
        position: fixed;
        bottom: -8mm;
        left: 0;
        right: 0;
        height: 6mm;
        font-size: 6.5pt;
    }

    .rodape table {
        width: 100%;
        border-collapse: collapse;
    }

    .rodape td {
        border: none;
        padding: 0;
    }

    .quebra-pagina {
        page-break-after: always;
    }

    .sem-quebra {
        page-break-inside: avoid;
    }
</style>
