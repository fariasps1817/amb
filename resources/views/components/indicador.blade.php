@props([
    'rotulo',
    'valor',
    'icone' => 'circulo',
    'rodape' => null,
    'href' => null,
])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200 transition sm:p-4 '.($href ? 'hover:ring-marca-300 hover:shadow' : '')]) }}
>
    <div class="flex items-start justify-between gap-2">
        <p class="text-xs font-medium text-slate-500">{{ $rotulo }}</p>
        <x-icone :nome="$icone" class="hidden size-4 shrink-0 text-slate-300 sm:block" />
    </div>

    <p class="mt-1 text-xl font-semibold text-slate-900 tabular-nums sm:mt-1.5 sm:text-2xl">{{ $valor }}</p>

    @if ($rodape)
        <p class="mt-0.5 truncate text-xs text-slate-400">{{ $rodape }}</p>
    @endif
</{{ $tag }}>
