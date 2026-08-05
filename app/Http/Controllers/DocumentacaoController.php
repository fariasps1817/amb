<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as Resposta;

/**
 * Entrega a documentacao tecnica do projeto pelo navegador.
 *
 * A pasta docs/ fica fora de public/ de proposito: o Nginx serve apenas
 * public/, e e isso que impede o .env, os logs e o vendor/ de serem alcancados
 * por URL. Publicar a documentacao copiando-a para public/ resolveria o acesso
 * e abriria um buraco: o guia de hospedagem descreve o IP do servidor, os
 * caminhos dos arquivos, onde ficam as senhas e como o firewall esta montado.
 *
 * Por isso ela passa por aqui, atras da autenticacao e restrita ao
 * administrador pelo middleware declarado na rota.
 */
class DocumentacaoController extends Controller
{
    /**
     * Documentos disponiveis.
     *
     * Lista fixa de proposito: aceitar um nome de arquivo vindo da URL
     * permitiria pedir "../.env" e afins.
     */
    private const DOCUMENTOS = [
        'hospedagem' => [
            'arquivo' => 'hospedagem-oracle-cloud.html',
            'titulo' => 'Montar o servidor do zero',
            'descricao' => 'Roteiro completo para hospedar o sistema na Oracle Cloud, do zero, de graça. Inclui as armadilhas encontradas na prática.',
        ],
    ];

    public function index(): View
    {
        $documentos = collect(self::DOCUMENTOS)->map(function (array $doc, string $apelido) {
            $caminho = base_path('docs/'.$doc['arquivo']);

            return [
                ...$doc,
                'apelido' => $apelido,
                'existe' => is_file($caminho),
                'tamanho' => is_file($caminho) ? Str::of(round(filesize($caminho) / 1024))->append(' KB') : null,
                'atualizado' => is_file($caminho) ? date('d/m/Y', filemtime($caminho)) : null,
            ];
        });

        return view('documentacao.index', ['documentos' => $documentos]);
    }

    public function mostrar(string $documento): Response
    {
        $doc = self::DOCUMENTOS[$documento] ?? abort(Resposta::HTTP_NOT_FOUND);

        $caminho = base_path('docs/'.$doc['arquivo']);

        abort_unless(is_file($caminho), Resposta::HTTP_NOT_FOUND, 'Documento ainda não foi gerado.');

        return response(file_get_contents($caminho), Resposta::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',

            // Documentacao interna nao deve ser guardada por proxy nem indexada.
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
