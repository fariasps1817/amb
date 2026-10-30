@props([
    'rota',
    'icone' => 'circulo',
    'ativo' => null,
    // Numero vermelho ao lado do item. O atributo data- e o que a atualizacao
    // automatica procura (ver "Contador de solicitacoes" no app.js).
    'contador' => null,
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
    class="menu-item group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{
        $ativo
            ? 'bg-white/15 text-white'
            : 'text-marca-100 hover:bg-white/10 hover:text-white'
    }}"
>
    <x-icone :nome="$icone" class="size-5 shrink-0 {{ $ativo ? 'text-white' : 'text-marca-300 group-hover:text-white' }}" />
    <span class="menu-texto truncate">{{ $slot }}</span>

    @if ($contador !== null)
        <span
            data-contador-solicitacoes
            @if ((int) $contador === 0) hidden @endif
            class="menu-contador absolute right-2 top-1/2 flex h-5 min-w-5 -translate-y-1/2 items-center justify-center rounded-full bg-rose-600 px-1 text-[0.7rem] font-bold text-white tabular-nums"
        >{{ $contador }}</span>
    @endif
</a>
