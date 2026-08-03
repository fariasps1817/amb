@props([
    'titulo' => null,
    'descricao' => null,
])

<section {{ $attributes->merge(['class' => 'rounded-xl bg-white shadow-sm ring-1 ring-slate-200']) }}>
    @if ($titulo || isset($acoes))
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
            <div class="min-w-0">
                @if ($titulo)
                    <h2 class="text-sm font-semibold text-slate-900">{{ $titulo }}</h2>
                @endif
                @if ($descricao)
                    <p class="mt-0.5 text-xs text-slate-500">{{ $descricao }}</p>
                @endif
            </div>

            @isset($acoes)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $acoes }}</div>
            @endisset
        </header>
    @endif

    <div @class(['px-4 py-4 sm:px-5', 'pt-0' => false])>
        {{ $slot }}
    </div>
</section>
