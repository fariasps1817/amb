<?php

/**
 * Converte um documento Markdown da pasta docs/ em uma pagina HTML autonoma,
 * pensada para leitura na tela e para impressao.
 *
 * Uso:
 *     php scripts/gerar-html-do-guia.php docs/hospedagem-oracle-cloud.md
 *     php scripts/gerar-html-do-guia.php                (usa o guia por padrao)
 *
 * O resultado e um unico arquivo .html, sem nenhuma dependencia externa: abre
 * offline, em qualquer navegador, e imprime com Ctrl+P -> Salvar como PDF.
 *
 * Por que nao gerar o PDF direto: o dompdf, que o sistema usa para os
 * documentos da escala, ignora quebra de pagina condicional e boa parte do CSS
 * moderno. Para um texto longo com tabelas, o motor de impressao do navegador
 * entrega resultado muito melhor -- e nao custa nenhuma dependencia nova.
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Str;

$origem = $argv[1] ?? __DIR__.'/../docs/hospedagem-oracle-cloud.md';

if (! is_file($origem)) {
    fwrite(STDERR, "Arquivo nao encontrado: {$origem}\n");
    exit(1);
}

$markdown = file_get_contents($origem);
$destino = preg_replace('/\.md$/', '.html', $origem);

// -----------------------------------------------------------------------
// Titulo e conversao
// -----------------------------------------------------------------------

preg_match('/^#\s+(.+)$/m', $markdown, $achado);
$titulo = trim($achado[1] ?? basename($origem, '.md'));

$corpo = Str::markdown($markdown, [
    'html_input' => 'allow',          // o guia usa <details> em alguns pontos
    'allow_unsafe_links' => false,
]);

// -----------------------------------------------------------------------
// Ancoras nos titulos e sumario
// -----------------------------------------------------------------------

/** Transforma "ETAPA 5 — Criar a máquina" em "etapa-5-criar-a-maquina". */
$apelido = function (string $texto): string {
    return trim(preg_replace('/-+/', '-',
        preg_replace('/[^a-z0-9]+/', '-', Str::lower(Str::ascii(strip_tags($texto))))
    ), '-');
};

$sumario = [];
$usados = [];

$corpo = preg_replace_callback(
    '/<h([12])>(.*?)<\/h\1>/s',
    function (array $t) use ($apelido, &$sumario, &$usados): string {
        [$tudo, $nivel, $texto] = $t;

        $id = $apelido($texto);

        // Dois titulos iguais gerariam a mesma ancora e o link levaria sempre
        // ao primeiro.
        if (isset($usados[$id])) {
            $id .= '-'.(++$usados[$id]);
        } else {
            $usados[$id] = 1;
        }

        $sumario[] = ['nivel' => (int) $nivel, 'id' => $id, 'texto' => strip_tags($texto)];

        return "<h{$nivel} id=\"{$id}\">{$texto}</h{$nivel}>";
    },
    $corpo
);

// O primeiro h1 e o titulo do documento. Ele ja aparece na capa, entao sai do
// sumario e tambem do corpo -- senao o leitor ve o mesmo titulo duas vezes
// seguidas, a segunda numa tarja verde.
array_shift($sumario);
$corpo = preg_replace('/<h1[^>]*>.*?<\/h1>\s*/s', '', $corpo, 1);

$listaSumario = '';
foreach ($sumario as $item) {
    $classe = $item['nivel'] === 1 ? 'parte' : 'etapa';
    $listaSumario .= sprintf(
        '<li class="%s"><a href="#%s">%s</a></li>'."\n",
        $classe,
        $item['id'],
        htmlspecialchars($item['texto'], ENT_QUOTES, 'UTF-8')
    );
}

// -----------------------------------------------------------------------
// Realce das citacoes de aviso
// -----------------------------------------------------------------------

// O guia usa "> ⚠️" para armadilhas e "> 🔒" para alertas de segurança.
// Marcar a citacao inteira permite dar cor a ela, em vez de so ao emoji.
$corpo = preg_replace(
    ['/<blockquote>\s*<p>⚠️/u', '/<blockquote>\s*<p>🔒/u'],
    ['<blockquote class="armadilha"><p>⚠️', '<blockquote class="seguranca"><p>🔒'],
    $corpo
);

// -----------------------------------------------------------------------
// Montagem da pagina
// -----------------------------------------------------------------------

$gerado = date('d/m/Y');
$tituloEscapado = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');

$html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$tituloEscapado}</title>
<style>
/* ---------------------------------------------------------------------------
   Tela
   --------------------------------------------------------------------------- */

:root {
    --marca: #265c59;
    --marca-clara: #eff9f7;
    --texto: #1e293b;
    --suave: #64748b;
    --borda: #e2e8f0;
    --fundo-codigo: #f8fafc;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 16px;
    line-height: 1.65;
    color: var(--texto);
    background: #f1f5f9;
}

.folha {
    max-width: 62rem;
    margin: 0 auto;
    padding: 3rem 3.5rem 5rem;
    background: #fff;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.1);
}

/* Cabecalho ------------------------------------------------------------- */

.capa {
    border-bottom: 3px solid var(--marca);
    padding-bottom: 1.25rem;
    margin-bottom: 2rem;
}

.capa h1 {
    margin: 0;
    font-size: 2.1rem;
    line-height: 1.2;
    color: var(--marca);
    border: 0;
    padding: 0;
}

.capa .assinatura {
    margin-top: 0.5rem;
    font-size: 0.85rem;
    color: var(--suave);
}

/* Titulos --------------------------------------------------------------- */

h1, h2, h3, h4 { line-height: 1.25; }

h1 {
    font-size: 1.75rem;
    color: #fff;
    background: var(--marca);
    padding: 0.6rem 1rem;
    border-radius: 6px;
    margin: 3.5rem 0 1.5rem;
}

h2 {
    font-size: 1.35rem;
    color: var(--marca);
    border-bottom: 2px solid var(--borda);
    padding-bottom: 0.35rem;
    margin: 2.5rem 0 1rem;
}

h3 {
    font-size: 1.1rem;
    margin: 1.75rem 0 0.6rem;
}

h4 {
    font-size: 1rem;
    color: var(--suave);
    margin: 1.25rem 0 0.5rem;
}

/* Sumario --------------------------------------------------------------- */

.sumario {
    background: var(--marca-clara);
    border: 1px solid #b4e1d9;
    border-radius: 8px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 2.5rem;
}

.sumario h2 {
    margin: 0 0 0.75rem;
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border: 0;
    padding: 0;
}

.sumario ol { margin: 0; padding: 0; list-style: none; }

.sumario li { font-size: 0.9rem; padding: 0.12rem 0; }

.sumario li.parte {
    font-weight: 600;
    margin-top: 0.6rem;
    padding-top: 0.4rem;
    border-top: 1px solid #b4e1d9;
}

.sumario li.parte:first-child { margin-top: 0; padding-top: 0; border-top: 0; }

.sumario li.etapa { padding-left: 1.1rem; }

.sumario a { color: var(--texto); text-decoration: none; }
.sumario a:hover { text-decoration: underline; }

/* Texto ----------------------------------------------------------------- */

a { color: #1d5f5b; }

hr {
    border: 0;
    border-top: 1px solid var(--borda);
    margin: 2.5rem 0;
}

/* Codigo ---------------------------------------------------------------- */

code {
    font-family: "Cascadia Mono", Consolas, "SF Mono", Menlo, monospace;
    font-size: 0.86em;
    background: #eef2f6;
    padding: 0.12em 0.35em;
    border-radius: 3px;
}

pre {
    background: var(--fundo-codigo);
    border: 1px solid var(--borda);
    border-left: 4px solid var(--marca);
    border-radius: 5px;
    padding: 0.9rem 1.1rem;
    overflow-x: auto;
    font-size: 0.84rem;
    line-height: 1.55;
}

pre code {
    background: 0;
    padding: 0;
    font-size: inherit;
}

/* Tabelas --------------------------------------------------------------- */

table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.25rem 0;
    font-size: 0.9rem;
}

th, td {
    border: 1px solid var(--borda);
    padding: 0.5rem 0.7rem;
    text-align: left;
    vertical-align: top;
}

th {
    background: var(--marca-clara);
    font-weight: 600;
    color: #1d4b48;
}

tbody tr:nth-child(even) { background: #fbfcfd; }

/* Citacoes -------------------------------------------------------------- */

blockquote {
    margin: 1.25rem 0;
    padding: 0.75rem 1.1rem;
    border-left: 4px solid #cbd5e1;
    background: #f8fafc;
    border-radius: 0 5px 5px 0;
}

blockquote > :first-child { margin-top: 0; }
blockquote > :last-child { margin-bottom: 0; }

blockquote.armadilha {
    border-left-color: #d97706;
    background: #fffbeb;
}

blockquote.seguranca {
    border-left-color: #dc2626;
    background: #fef2f2;
}

details {
    border: 1px solid var(--borda);
    border-radius: 5px;
    padding: 0.75rem 1rem;
    margin: 1.25rem 0;
    background: var(--fundo-codigo);
}

summary { cursor: pointer; font-weight: 600; }

/* ---------------------------------------------------------------------------
   Impressao
   --------------------------------------------------------------------------- */

@page {
    size: A4;
    margin: 16mm 14mm 18mm;
}

@media print {

    body {
        background: #fff;
        font-size: 10.5pt;
        line-height: 1.45;
    }

    .folha {
        max-width: none;
        margin: 0;
        padding: 0;
        box-shadow: none;
    }

    .aviso-impressao { display: none; }

    /* Cada PARTE comeca em folha nova. O seletor precisa ser ".folha > h1"
       e nao "h1": o titulo da capa tambem e um h1, e uma quebra antes dele
       produziria uma primeira pagina em branco. */
    .folha > h1 { page-break-before: always; }
    .capa h1 { page-break-before: avoid; }

    /* Um titulo sozinho no pe da pagina nao ajuda ninguem. */
    h1, h2, h3, h4 { page-break-after: avoid; break-after: avoid; }

    /* Partir um comando ao meio inutiliza o trecho para quem esta copiando. */
    pre, table, blockquote, details {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    tr, li { page-break-inside: avoid; }

    /* Cores de fundo so saem na impressao se forem explicitamente pedidas. */
    h1, th, blockquote, pre, .sumario {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    h1 {
        font-size: 15pt;
        padding: 0.35rem 0.7rem;
    }

    h2 { font-size: 12.5pt; }
    h3 { font-size: 11pt; }

    pre { font-size: 8pt; }
    table { font-size: 8.5pt; }

    a { color: var(--texto); text-decoration: none; }

    /* No papel o link nao clica: o endereco precisa estar escrito.
       Vale so para enderecos externos -- imprimir "#etapa-5" ao lado de cada
       item do sumario deixaria a lista ilegivel. */
    a[href^="http"]::after {
        content: " (" attr(href) ")";
        font-size: 7.5pt;
        color: #475569;
        word-break: break-all;
    }

    .sumario { page-break-after: always; }
}
</style>
</head>
<body>
<div class="folha">

    <header class="capa">
        <h1>{$tituloEscapado}</h1>
        <p class="assinatura">
            Gerado em {$gerado} · Coordenação de Ambulâncias · Secretaria Municipal de Saúde
        </p>
    </header>

    <p class="aviso-impressao">
        <em>Para gerar um PDF: <strong>Ctrl+P</strong> → Destino
        <strong>Salvar como PDF</strong> → marque <strong>Gráficos de segundo
        plano</strong> para manter as cores dos títulos e tabelas.</em>
    </p>

    <nav class="sumario">
        <h2>Conteúdo</h2>
        <ol>
{$listaSumario}
        </ol>
    </nav>

{$corpo}

</div>
</body>
</html>
HTML;

file_put_contents($destino, $html);

printf(
    "Gerado: %s\n  %d KB · %d seções no sumário\n",
    $destino,
    (int) round(strlen($html) / 1024),
    count($sumario)
);
