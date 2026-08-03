<?php

namespace App\Http\Requests;

use App\Support\Regime;
use App\Support\Telefone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class UnidadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->podeEditar() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // O formulario oferece o regime na notacao do dia a dia ("24/72"); aqui
        // desmembramos nas duas colunas gravadas no banco.
        //
        // O desmembramento e feito pelo formato, sem instanciar Regime: um valor
        // como "24/50" tem formato valido mas nao fecha ciclo, e queremos que o
        // erro apareca no campo "regime" (que o operador ve) e nao nos campos de
        // horas, que sao apenas derivados.
        if (preg_match('/^\s*(\d{1,3})\s*\/\s*(\d{1,3})\s*$/', (string) $this->input('regime'), $m)) {
            $this->merge([
                'horas_trabalho' => (int) $m[1],
                'horas_descanso' => (int) $m[2],
            ]);
        }

        $this->merge([
            'sigla' => mb_strtoupper(trim((string) $this->input('sigla'))),
            'uf' => mb_strtoupper(trim((string) $this->input('uf'))) ?: null,
            'telefone_1' => Telefone::digitos($this->input('telefone_1')) ?: null,
            'telefone_2' => Telefone::digitos($this->input('telefone_2')) ?: null,
            'ativo' => $this->boolean('ativo'),
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('unidade')?->id;

        return [
            'nome' => ['required', 'string', 'max:255'],
            'sigla' => ['required', 'string', 'max:30', Rule::unique('unidades', 'sigla')->ignore($id)],
            'tipo' => ['nullable', 'string', 'max:40'],

            'endereco' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:255'],
            'cidade' => ['nullable', 'string', 'max:255'],
            'uf' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'string', 'max:9'],

            'responsavel' => ['nullable', 'string', 'max:255'],
            'cargo_responsavel' => ['nullable', 'string', 'max:255'],
            'telefone_1' => ['nullable', 'digits_between:8,13'],
            'telefone_2' => ['nullable', 'digits_between:8,13'],
            'email' => ['nullable', 'email', 'max:255'],

            'regime' => ['required', 'string', 'max:10'],
            'horas_trabalho' => ['required', 'integer', 'min:1', 'max:24'],
            'horas_descanso' => ['required', 'integer', 'min:0', 'max:240'],

            'ordem' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'ativo' => ['boolean'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['horas_trabalho', 'horas_descanso'])) {
                    return;
                }

                // O regime precisa fechar um ciclo inteiro de plantoes, senao a
                // escala geraria dias sobrepostos ou descobertos.
                try {
                    new Regime(
                        (int) $this->input('horas_trabalho'),
                        (int) $this->input('horas_descanso'),
                    );
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('regime', $e->getMessage());
                }
            },
        ];
    }

    /**
     * Somente as colunas do model; "regime" e apenas um campo de entrada.
     */
    public function dadosDaUnidade(): array
    {
        return collect($this->validated())->except('regime')->all();
    }
}
