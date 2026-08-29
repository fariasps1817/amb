@props([
    'rota',
    'icone' => 'circulo',
    'ativo' => null,
])

@php
    // Quando o chamador nao informa, considera ativo apenas a rota exata.
    $ativo = $ativo ?? request()->routeIs($rota);

    // Com a barra recolhida so aparece o icone; o title diz o que ele e.
    $rotulo = trim(strip_tags($slot->toHtml()));
@endphp

<a
    href="{{ route($rota) }}"
    title="{{ $rotulo }}"
    @if ($ativo) aria-current="page" @endif
    class="menu-item group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{
        $ativo
            ? 'bg-white/15 text-white'
            : 'text-marca-100 hover:bg-white/10 hover:text-white'
    }}"
>
    <x-icone :nome="$icone" class="size-5 shrink-0 {{ $ativo ? 'text-white' : 'text-marca-300 group-hover:text-white' }}" />
    <span class="menu-texto truncate">{{ $slot }}</span>
</a>
