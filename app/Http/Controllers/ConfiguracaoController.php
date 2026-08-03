<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Support\Telefone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Identidade institucional aplicada aos documentos gerados.
 */
class ConfiguracaoController extends Controller
{
    /** Campos de imagem aceitos. */
    private const IMAGENS = [
        'logo_prefeitura' => 'Logo da prefeitura',
        'logo_secretaria' => 'Logo da secretaria',
        'brasao' => 'Brasão do município',
        'imagem_ambulancia' => 'Imagem decorativa da ambulância',
    ];

    public function edit(): View
    {
        return view('configuracoes.edit', [
            'configuracao' => Configuracao::atual(),
            'imagens' => self::IMAGENS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);

        $dados = $request->validate([
            'municipio' => ['nullable', 'string', 'max:255'],
            'prefeitura' => ['nullable', 'string', 'max:255'],
            'secretaria' => ['nullable', 'string', 'max:255'],
            'setor' => ['nullable', 'string', 'max:255'],
            'slogan' => ['nullable', 'string', 'max:255'],

            'endereco' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:255'],
            'cidade' => ['nullable', 'string', 'max:255'],
            'uf' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'string', 'max:9'],
            'cnpj' => ['nullable', 'string', 'max:18'],

            'telefone_1' => ['nullable', 'string', 'max:20'],
            'telefone_2' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],

            'responsavel_setor' => ['nullable', 'string', 'max:255'],
            'cargo_responsavel' => ['nullable', 'string', 'max:255'],
            'rodape_documentos' => ['nullable', 'string', 'max:255'],

            // Imagens: PNG/JPG/SVG/WEBP ate 2 MB. O dompdf embute o arquivo no
            // PDF, por isso limitamos o tamanho.
            'logo_prefeitura' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'logo_secretaria' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'brasao' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'imagem_ambulancia' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $configuracao = Configuracao::atual();

        // Telefones guardados somente com digitos.
        foreach (['telefone_1', 'telefone_2'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $dados[$campo] = Telefone::digitos($dados[$campo]) ?: null;
            }
        }

        if (isset($dados['uf'])) {
            $dados['uf'] = mb_strtoupper($dados['uf']);
        }

        foreach (array_keys(self::IMAGENS) as $campo) {
            if (! $request->hasFile($campo)) {
                unset($dados[$campo]);

                continue;
            }

            // Substitui a imagem anterior para nao acumular arquivos orfaos.
            $this->apagarArquivo($configuracao->{$campo});

            $dados[$campo] = $request->file($campo)->store(Configuracao::PASTA_IMAGENS, 'public');
        }

        $configuracao->update($dados);

        return redirect()
            ->route('configuracoes.edit')
            ->with('sucesso', 'Identidade institucional atualizada. Os documentos passam a usar estes dados.');
    }

    public function removerImagem(Request $request, string $campo): RedirectResponse
    {
        abort_unless($request->user()->podeEditar(), 403);
        abort_unless(array_key_exists($campo, self::IMAGENS), 404);

        $configuracao = Configuracao::atual();

        $this->apagarArquivo($configuracao->{$campo});
        $configuracao->update([$campo => null]);

        return redirect()
            ->route('configuracoes.edit')
            ->with('sucesso', self::IMAGENS[$campo].' removida.');
    }

    private function apagarArquivo(?string $caminho): void
    {
        if ($caminho && Storage::disk('public')->exists($caminho)) {
            Storage::disk('public')->delete($caminho);
        }
    }
}
