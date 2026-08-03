<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfiguracaoRequest;
use App\Models\Configuracao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Identidade institucional aplicada aos documentos gerados.
 */
class ConfiguracaoController extends Controller
{
    public function edit(): View
    {
        return view('configuracoes.edit', [
            'configuracao' => Configuracao::atual(),
            'imagens' => Configuracao::CAMPOS_DE_IMAGEM,
        ]);
    }

    public function update(ConfiguracaoRequest $request): RedirectResponse
    {
        $configuracao = Configuracao::atual();
        $dados = $request->dadosDeTexto();
        $enviadas = 0;

        foreach (array_keys(Configuracao::CAMPOS_DE_IMAGEM) as $campo) {
            if (! $request->hasFile($campo)) {
                continue;
            }

            // Substitui a imagem anterior para nao acumular arquivos orfaos.
            $this->apagarArquivo($configuracao->{$campo});

            $dados[$campo] = $request->file($campo)->store(Configuracao::PASTA_IMAGENS, 'public');
            $enviadas++;
        }

        $configuracao->update($dados);

        $mensagem = 'Identidade institucional atualizada.';

        if ($enviadas > 0) {
            $mensagem .= " {$enviadas} imagem(ns) enviada(s) — os documentos passam a usá-la(s) no cabeçalho.";
        }

        return redirect()->route('configuracoes.edit')->with('sucesso', $mensagem);
    }

    public function removerImagem(Request $request, string $campo): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless(array_key_exists($campo, Configuracao::CAMPOS_DE_IMAGEM), 404);

        $configuracao = Configuracao::atual();

        $this->apagarArquivo($configuracao->{$campo});
        $configuracao->update([$campo => null]);

        return redirect()
            ->route('configuracoes.edit')
            ->with('sucesso', Configuracao::CAMPOS_DE_IMAGEM[$campo].' removida.');
    }

    private function apagarArquivo(?string $caminho): void
    {
        if ($caminho && Storage::disk('public')->exists($caminho)) {
            Storage::disk('public')->delete($caminho);
        }
    }
}
