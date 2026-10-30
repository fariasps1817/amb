<?php

namespace App\Http\Requests;

use App\Enums\Locomocao;
use App\Support\MunicipiosDoCeara;
use App\Support\Telefone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Formulario publico de alta hospitalar: nao ha login, entao nada aqui confia em
 * quem envia. So o que esta nas regras e gravado -- data, status e municipio de
 * destino sao definidos pelo servidor, mesmo que venham na requisicao.
 */
class SolicitacaoDeAltaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Pagina aberta: um cliente com a codificacao errada (robos, em geral)
        // nao pode derrubar a gravacao com erro 500.
        $this->merge(array_map(
            fn ($valor) => is_string($valor) ? mb_scrub($valor, 'UTF-8') : $valor,
            $this->all()
        ));

        $this->merge([
            'solicitante_telefone' => Telefone::digitos($this->input('solicitante_telefone')),
            'contato_telefone_1' => Telefone::digitos($this->input('contato_telefone_1')),
            'contato_telefone_2' => Telefone::digitos($this->input('contato_telefone_2')) ?: null,
            'consciente' => $this->boolean('consciente'),
            'orientado' => $this->boolean('orientado'),
            'puerpera_com_rn' => $this->boolean('puerpera_com_rn'),
            'necessita_oxigenio' => $this->boolean('necessita_oxigenio'),
            'necessita_aspirador' => $this->boolean('necessita_aspirador'),
            'necessita_tecnico' => $this->boolean('necessita_tecnico'),
        ]);
    }

    public function rules(): array
    {
        return [
            'hospital_nome' => ['required', 'string', 'max:150'],
            'hospital_cidade' => ['required', Rule::in(MunicipiosDoCeara::LISTA)],
            'hospital_bairro' => ['required', 'string', 'max:100'],

            'solicitante_nome' => ['required', 'string', 'max:120'],
            'solicitante_funcao' => ['required', 'string', 'max:80'],
            // Com DDD: os hospitais ficam em varios municipios.
            'solicitante_telefone' => ['required', 'digits_between:10,11'],

            'paciente_nome' => ['required', 'string', 'max:150'],
            'paciente_idade' => ['required', 'integer', 'min:0', 'max:130'],
            'paciente_idade_unidade' => ['required', Rule::in(['anos', 'meses'])],
            'acompanhante_nome' => ['nullable', 'string', 'max:150'],
            'contato_telefone_1' => ['required', 'digits_between:10,11'],
            'contato_telefone_2' => ['nullable', 'digits_between:10,11'],

            'destino_endereco' => ['required', 'string', 'max:200'],
            'destino_bairro' => ['required', 'string', 'max:100'],
            'destino_referencia' => ['nullable', 'string', 'max:200'],

            'locomocao' => ['required', Rule::enum(Locomocao::class)],
            'consciente' => ['boolean'],
            'orientado' => ['boolean'],
            'puerpera_com_rn' => ['boolean'],
            'necessita_oxigenio' => ['boolean'],
            'necessita_aspirador' => ['boolean'],
            'necessita_tecnico' => ['boolean'],
            'observacoes_paciente' => ['nullable', 'string', 'max:1000'],

            'declaracao' => ['accepted'],
            'chave_envio' => ['nullable', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'hospital_nome' => 'nome do hospital',
            'hospital_cidade' => 'cidade do hospital',
            'hospital_bairro' => 'bairro do hospital',
            'solicitante_nome' => 'seu nome',
            'solicitante_funcao' => 'sua função',
            'solicitante_telefone' => 'telefone de contato',
            'paciente_nome' => 'nome do paciente',
            'paciente_idade' => 'idade do paciente',
            'paciente_idade_unidade' => 'unidade da idade',
            'acompanhante_nome' => 'nome do acompanhante',
            'contato_telefone_1' => 'telefone do paciente ou acompanhante',
            'contato_telefone_2' => 'segundo telefone',
            'destino_endereco' => 'endereço',
            'destino_bairro' => 'bairro ou localidade',
            'destino_referencia' => 'ponto de referência',
            'locomocao' => 'locomoção do paciente',
            'observacoes_paciente' => 'observações',
        ];
    }

    public function messages(): array
    {
        return [
            'declaracao.accepted' => 'Confirme que você é profissional deste hospital.',
            'hospital_cidade.in' => 'Escolha a cidade do hospital na lista.',
            'solicitante_telefone.digits_between' => 'Informe o telefone de contato com DDD.',
            'contato_telefone_1.digits_between' => 'Informe o telefone do paciente ou acompanhante com DDD.',
            'contato_telefone_2.digits_between' => 'Informe o segundo telefone com DDD.',
        ];
    }
}
