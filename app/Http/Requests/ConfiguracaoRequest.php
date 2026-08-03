<?php

namespace App\Http\Requests;

use App\Models\Configuracao;
use App\Support\Telefone;
use Illuminate\Foundation\Http\FormRequest;

class ConfiguracaoRequest extends FormRequest
{
    /**
     * Em caso de erro, volta sempre para a tela de identidade institucional.
     *
     * Sem isso o Laravel usaria back(), que depende do cabecalho Referer; quando
     * ele nao chega, o operador e jogado no painel e a mensagem de erro se perde
     * — dando a impressao de que o upload simplesmente nao funciona.
     */
    protected $redirectRoute = 'configuracoes.edit';

    public function authorize(): bool
    {
        return $this->user()?->podeEditar() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'telefone_1' => Telefone::digitos($this->input('telefone_1')) ?: null,
            'telefone_2' => Telefone::digitos($this->input('telefone_2')) ?: null,
            'uf' => mb_strtoupper(trim((string) $this->input('uf'))) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
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

            'telefone_1' => ['nullable', 'digits_between:8,13'],
            'telefone_2' => ['nullable', 'digits_between:8,13'],
            'email' => ['nullable', 'email', 'max:255'],
            'site' => ['nullable', 'string', 'max:255'],

            'responsavel_setor' => ['nullable', 'string', 'max:255'],
            'cargo_responsavel' => ['nullable', 'string', 'max:255'],
            'rodape_documentos' => ['nullable', 'string', 'max:255'],

            // Imagens ate 2 MB — o dompdf embute o arquivo no PDF, por isso o
            // limite de tamanho.
            //
            // A regra "image" sozinha rejeita SVG (o Laravel o exclui por
            // padrao, pois um SVG pode conter script). Como brasoes municipais
            // costumam vir em SVG e a tela os oferece, usamos allow_svg e
            // servimos o arquivo apenas dentro de <img>, contexto em que o
            // script nao executa.
            //
            // "bail" evita duas mensagens para o mesmo arquivo: sem ele, um
            // arquivo que nao e imagem falha em image e em mimes ao mesmo tempo.
            ...array_fill_keys(array_keys(Configuracao::CAMPOS_DE_IMAGEM), [
                'bail',
                'nullable',
                'image:allow_svg',
                'mimes:png,jpg,jpeg,svg,webp',
                'max:2048',
            ]),
        ];
    }

    public function messages(): array
    {
        return [
            'max' => 'A imagem :attribute não pode passar de 2 MB.',
            'mimes' => 'A imagem :attribute deve estar em PNG, JPG, SVG ou WEBP.',
            'image' => 'O arquivo enviado em :attribute não é uma imagem válida.',
        ];
    }

    /** Somente os campos de texto; as imagens sao tratadas no controller. */
    public function dadosDeTexto(): array
    {
        return collect($this->validated())
            ->except(array_keys(Configuracao::CAMPOS_DE_IMAGEM))
            ->all();
    }
}
