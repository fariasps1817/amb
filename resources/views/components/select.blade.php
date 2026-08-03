@props([
    'rotulo' => null,
    'nome' => null,
    'opcoes' => [],
    'selecionado' => null,
    'vazio' => null,
    'ajuda' => null,
    'obrigatorio' => false,
])

@php
    $nome = $nome ?? $attributes->get('name');
    $erro = $nome ? $errors->first($nome) : null;
    $selecionado = old($nome, $selecionado);
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

    <select
        @if ($nome) id="campo-{{ $nome }}" name="{{ $nome }}" @endif
        @if ($erro) aria-invalid="true" aria-describedby="erro-{{ $nome }}" @endif
        {{ $attributes->merge([
            'class' => 'block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset transition focus:ring-2 focus:ring-inset disabled:cursor-not-allowed disabled:bg-slate-50 sm:text-sm '
                . ($erro
                    ? 'ring-rose-400 focus:ring-rose-500'
                    : 'ring-slate-300 focus:ring-marca-600'),
        ]) }}
    >
        @if ($vazio !== null)
            <option value="">{{ $vazio }}</option>
        @endif

        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
            @foreach ($opcoes as $valor => $texto)
                <option value="{{ $valor }}" @selected((string) $selecionado === (string) $valor)>
                    {{ $texto }}
                </option>
            @endforeach
        @endif
    </select>

    @if ($erro)
        <p id="erro-{{ $nome }}" class="mt-1.5 text-sm text-rose-600">{{ $erro }}</p>
    @elseif ($ajuda)
        <p class="mt-1.5 text-xs text-slate-500">{{ $ajuda }}</p>
    @endif
</div>
