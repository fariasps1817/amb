@props([
    'rotulo' => null,
    'nome' => null,
    'tipo' => 'text',
    'ajuda' => null,
    'obrigatorio' => false,
    'mascara' => null,
])

@php
    // Livewire usa wire:model; formularios comuns usam name/value.
    $nome = $nome ?? $attributes->get('name');
    $erro = $nome ? $errors->first($nome) : null;

    /*
        Campos com mascara aceitam somente digitos e sao pontuados pelo
        JavaScript (resources/js/app.js) enquanto o usuario digita.

        O inputmode numerico e o que faz o celular abrir o teclado de numeros;
        "tel" tambem abriria, mas com o teclado de discagem, que traz + * # --
        caracteres que a mascara descartaria em seguida.

        O maxlength conta a pontuacao, por isso e maior que a quantidade de
        digitos: 14 para o CPF e 15 para o telefone.
    */
    $porMascara = [
        'cpf' => [
            'data-mascara' => 'cpf',
            'inputmode' => 'numeric',
            'maxlength' => 14,
            'placeholder' => '000.000.000-00',
            // O preenchimento automatico do navegador ignora a mascara e
            // deixaria o campo com pontuacao propria.
            'autocomplete' => 'off',
        ],
        'telefone' => [
            'data-mascara' => 'telefone',
            'inputmode' => 'numeric',
            'maxlength' => 15,
            'placeholder' => '(85) 98692-6853',
            'autocomplete' => 'tel',
        ],
    ];

    $atributosDaMascara = $porMascara[$mascara] ?? [];
@endphp

<div class="w-full">
    @if ($rotulo)
        <label
            @if ($nome) for="campo-{{ $nome }}" @endif
            class="mb-1.5 block text-sm font-medium text-slate-700"
        >
            {{ $rotulo }}
            @if ($obrigatorio)
                <span class="text-rose-500" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <input
        type="{{ $tipo }}"
        @if ($nome) id="campo-{{ $nome }}" name="{{ $nome }}" @endif
        @if ($erro) aria-invalid="true" aria-describedby="erro-{{ $nome }}" @endif
        {{ $attributes->merge($atributosDaMascara + [
            'class' => 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset transition placeholder:text-slate-400 focus:ring-2 focus:ring-inset disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 sm:text-sm '
                . ($erro
                    ? 'ring-rose-400 focus:ring-rose-500'
                    : 'ring-slate-300 focus:ring-marca-600'),
        ]) }}
    />

    @if ($erro)
        <p id="erro-{{ $nome }}" class="mt-1.5 text-sm text-rose-600">{{ $erro }}</p>
    @elseif ($ajuda)
        <p class="mt-1.5 text-xs text-slate-500">{{ $ajuda }}</p>
    @endif
</div>
