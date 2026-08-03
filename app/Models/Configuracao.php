<?php

namespace App\Models;

use App\Support\Telefone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Identidade institucional usada nos cabecalhos e rodapes dos documentos.
 *
 * Registro unico: sempre a linha de id = 1. Use Configuracao::atual().
 */
#[Fillable([
    'municipio', 'prefeitura', 'secretaria', 'setor', 'slogan',
    'endereco', 'bairro', 'cidade', 'uf', 'cep', 'cnpj',
    'telefone_1', 'telefone_2', 'email', 'site',
    'logo_prefeitura', 'logo_secretaria', 'brasao', 'imagem_ambulancia',
    'responsavel_setor', 'cargo_responsavel', 'rodape_documentos',
])]
class Configuracao extends Model
{
    protected $table = 'configuracoes';

    /** Caminho no disco publico onde as imagens institucionais sao guardadas. */
    public const PASTA_IMAGENS = 'institucional';

    /**
     * Retorna a configuracao vigente, criando o registro vazio na primeira
     * execucao para que as telas nunca recebam null.
     *
     * Como a tabela guarda um unico registro, buscamos o primeiro em vez de
     * fixar o id — assim nao dependemos de atribuicao em massa da chave.
     */
    public static function atual(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::query()->create([
                'municipio' => '',
                'prefeitura' => '',
                'secretaria' => '',
                'setor' => '',
            ]);
    }

    // -----------------------------------------------------------------
    // Imagens
    // -----------------------------------------------------------------

    /** URL publica da imagem, para exibicao nas telas. */
    public function urlImagem(string $campo): ?string
    {
        $caminho = $this->{$campo};

        if (! $caminho) {
            return null;
        }

        return Storage::disk('public')->url($caminho);
    }

    /**
     * Caminho absoluto da imagem no disco.
     *
     * O dompdf nao acessa URLs http do proprio servidor de forma confiavel; nos
     * PDFs as imagens sao embutidas em base64 a partir deste caminho.
     */
    public function caminhoImagem(string $campo): ?string
    {
        $caminho = $this->{$campo};

        if (! $caminho) {
            return null;
        }

        $absoluto = Storage::disk('public')->path($caminho);

        return is_file($absoluto) ? $absoluto : null;
    }

    /**
     * Imagem embutida como data URI, pronta para o atributo src do PDF.
     */
    public function imagemBase64(string $campo): ?string
    {
        $absoluto = $this->caminhoImagem($campo);

        if ($absoluto === null) {
            return null;
        }

        $conteudo = @file_get_contents($absoluto);

        if ($conteudo === false) {
            return null;
        }

        $mime = match (strtolower(pathinfo($absoluto, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return "data:{$mime};base64,".base64_encode($conteudo);
    }

    // -----------------------------------------------------------------
    // Apresentacao
    // -----------------------------------------------------------------

    public function enderecoCompleto(): string
    {
        return collect([
            $this->endereco,
            $this->bairro,
            trim(collect([$this->cidade, $this->uf])->filter()->implode('/')),
            $this->cep ? 'CEP '.$this->cep : null,
        ])->filter()->implode(', ');
    }

    public function telefonesFormatados(): string
    {
        return collect([$this->telefone_1, $this->telefone_2])
            ->filter()
            ->map(fn ($t) => Telefone::formatar($t))
            ->implode(' / ');
    }

    /** Linha de rodape dos documentos. */
    public function rodape(): string
    {
        if ($this->rodape_documentos) {
            return $this->rodape_documentos;
        }

        return collect([$this->secretaria, $this->setor])->filter()->implode(' — ');
    }

    /** Local e data por extenso: "Cascavel-CE, 03 de agosto de 2026". */
    public function localData(?\DateTimeInterface $data = null): string
    {
        $data = $data ? \Illuminate\Support\Carbon::instance($data) : now();
        $local = trim(collect([$this->cidade, $this->uf])->filter()->implode('-'));

        $texto = $data->translatedFormat('d \d\e F \d\e Y');

        return $local !== '' ? "{$local}, {$texto}" : $texto;
    }
}
