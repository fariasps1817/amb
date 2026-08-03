<?php

namespace App\Http\Requests;

use App\Enums\VinculoAmbulancia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AmbulanciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->podeEditar() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Placa gravada sem hifen e em caixa alta, como impressa na planilha.
        $placa = preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input('placa'));

        $this->merge([
            'placa' => mb_strtoupper((string) $placa),
            'renavam' => preg_replace('/\D/', '', (string) $this->input('renavam')) ?: null,
            'identificacao' => mb_strtoupper(trim((string) $this->input('identificacao'))) ?: null,
            'unidade_id' => $this->input('unidade_id') ?: null,
            'ativo' => $this->boolean('ativo'),
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('ambulancia')?->id;
        $anoLimite = (int) now()->year + 2;

        return [
            // Aceita o padrao antigo (AAA1234) e o Mercosul (AAA1A23).
            'placa' => [
                'required',
                'string',
                'regex:/^[A-Z]{3}[0-9][0-9A-Z][0-9]{2}$/',
                Rule::unique('ambulancias', 'placa')->ignore($id),
            ],
            'renavam' => ['nullable', 'digits_between:9,11', Rule::unique('ambulancias', 'renavam')->ignore($id)],
            'vinculo' => ['required', Rule::enum(VinculoAmbulancia::class)],

            'marca' => ['nullable', 'string', 'max:60'],
            'modelo' => ['nullable', 'string', 'max:60'],
            'ano_fabricacao' => ['nullable', 'integer', 'min:1980', "max:{$anoLimite}"],
            'ano_modelo' => ['nullable', 'integer', 'min:1980', "max:{$anoLimite}"],
            'tipo' => ['nullable', 'string', 'max:40'],

            'identificacao' => ['nullable', 'string', 'max:40'],
            'unidade_id' => ['nullable', 'exists:unidades,id'],

            'ativo' => ['boolean'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'placa.regex' => 'Informe uma placa válida, no padrão ABC1234 ou ABC1D23.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $fabricacao = $this->input('ano_fabricacao');
                $modelo = $this->input('ano_modelo');

                // O ano do modelo nunca antecede o de fabricacao.
                if (filled($fabricacao) && filled($modelo) && (int) $modelo < (int) $fabricacao) {
                    $validator->errors()->add(
                        'ano_modelo',
                        'O ano do modelo não pode ser anterior ao ano de fabricação.'
                    );
                }
            },
        ];
    }
}
