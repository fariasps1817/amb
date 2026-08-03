@props([
    'variante' => 'primario',
    'tamanho' => 'padrao',
    'href' => null,
    'icone' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60';

    $variantes = [
        'primario' => 'bg-marca-600 text-white shadow-sm hover:bg-marca-700 focus-visible:outline-marca-600',
        'secundario' => 'bg-white text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-slate-400',
        'perigo' => 'bg-rose-600 text-white shadow-sm hover:bg-rose-700 focus-visible:outline-rose-600',
        'suave' => 'bg-marca-50 text-marca-700 ring-1 ring-inset ring-marca-200 hover:bg-marca-100 focus-visible:outline-marca-600',
        'texto' => 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-slate-400',
    ];

    $tamanhos = [
        'pequeno' => 'px-2.5 py-1.5 text-xs',
        'padrao' => 'px-3.5 py-2 text-sm',
        'grande' => 'px-4 py-2.5 text-sm',
    ];

    $classes = implode(' ', [
        $base,
        $variantes[$variante] ?? $variantes['primario'],
        $tamanhos[$tamanho] ?? $tamanhos['padrao'],
    ]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icone)
            <x-icone :nome="$icone" class="size-4 shrink-0" />
        @endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        @if ($icone)
            <x-icone :nome="$icone" class="size-4 shrink-0" />
        @endif
        {{ $slot }}
    </button>
@endif
