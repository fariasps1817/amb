@props([
    'rotulo' => null,
    'nome' => null,
    'tipo' => 'text',
    'ajuda' => null,
    'obrigatorio' => false,
])

@php
    // Livewire usa wire:model; formularios comuns usam name/value.
    $nome = $nome ?? $attributes->get('name');
    $erro = $nome ? $errors->first($nome) : null;
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
        {{ $attributes->merge([
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
