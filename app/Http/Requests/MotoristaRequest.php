<?php

namespace App\Http\Requests;

use App\Enums\StatusMotorista;
use App\Enums\Vinculo;
use App\Support\Telefone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MotoristaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->podeEditar() ?? false;
    }

    /**
     * Normaliza os campos antes da validacao: telefones e CPF ficam gravados
     * somente com digitos, e o nome em caixa alta como nos documentos.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf' => Telefone::digitos($this->input('cpf')) ?: null,
            'telefone_1' => Telefone::digitos($this->input('telefone_1')) ?: null,
            'telefone_2' => Telefone::digitos($this->input('telefone_2')) ?: null,
            'nome_completo' => mb_strtoupper(trim((string) $this->input('nome_completo'))),
            'nome_curto' => mb_strtoupper(trim((string) $this->input('nome_curto'))),
            'cnh_categoria' => mb_strtoupper(trim((string) $this->input('cnh_categoria'))) ?: null,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('motorista')?->id;

        return [
            'nome_completo' => ['required', 'string', 'max:255'],
            'nome_curto' => ['required', 'string', 'max:60'],

            'cpf' => ['nullable', 'digits:11', Rule::unique('motoristas', 'cpf')->ignore($id)->whereNull('deleted_at')],
            'data_nascimento' => ['nullable', 'date', 'before:today'],

            'vinculo' => ['required', Rule::enum(Vinculo::class)],
            'vinculo_inicio' => ['nullable', 'date'],
            'vinculo_fim' => ['nullable', 'date', 'after_or_equal:vinculo_inicio'],

            'cnh_numero' => ['nullable', 'string', 'max:20'],
            'cnh_categoria' => ['nullable', 'string', 'max:10'],
            'cnh_validade' => ['nullable', 'date'],

            'telefone_1' => ['nullable', 'digits_between:8,13'],
            'telefone_2' => ['nullable', 'digits_between:8,13'],

            'matricula' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::enum(StatusMotorista::class)],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Regras que dependem da combinacao de campos.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Contrato temporario precisa de prazo: sem ele o sistema nao
                // consegue avisar quando o motorista deixa de poder ser escalado.
                if ($this->input('vinculo') === Vinculo::Contrato->value && blank($this->input('vinculo_fim'))) {
                    $validator->errors()->add(
                        'vinculo_fim',
                        'Informe a data de término para vínculo por contrato temporário.'
                    );
                }

                // Efetivo nao tem data de fim.
                if ($this->input('vinculo') === Vinculo::Efetivo->value && filled($this->input('vinculo_fim'))) {
                    $validator->errors()->add(
                        'vinculo_fim',
                        'Servidor efetivo não possui data de término de vínculo.'
                    );
                }

                // Motorista ativo precisa de telefone: sem ele nao ha como
                // enviar a mensagem de plantao pelo WhatsApp.
                if ($this->input('status') === StatusMotorista::Ativo->value && blank($this->input('telefone_1'))) {
                    $validator->errors()->add(
                        'telefone_1',
                        'Motorista ativo precisa de um telefone para receber a escala pelo WhatsApp.'
                    );
                }
            },
        ];
    }
}
