@props([
    'titulo',
    'descricao',
    'icone' => 'documentos',
    'detalhes' => [],
    'ver',
    'baixar' => null,
])

<div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
    <div class="flex flex-wrap items-start gap-4">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-marca-50 ring-1 ring-marca-100">
            <x-icone :nome="$icone" class="size-5 text-marca-700" />
        </span>

        <div class="min-w-0 flex-1">
            <h3 class="text-sm font-semibold text-slate-900">{{ $titulo }}</h3>
            <p class="mt-1 text-sm text-slate-600">{{ $descricao }}</p>

            @if ($detalhes)
                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                    @foreach ($detalhes as $detalhe)
                        <span class="flex items-center gap-1">
                            @unless ($loop->first)
                                <span class="text-slate-300">·</span>
                            @endunless
                            {{ $detalhe }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex w-full shrink-0 gap-2 sm:w-auto">
            <x-botao :href="$ver" target="_blank" tamanho="pequeno" icone="olho" class="flex-1 sm:flex-none">
                Visualizar
            </x-botao>

            @if ($baixar)
                <x-botao :href="$baixar" variante="secundario" tamanho="pequeno" icone="documentos" class="flex-1 sm:flex-none">
                    Baixar
                </x-botao>
            @endif
        </div>
    </div>

    {{-- Opções específicas do documento, quando houver --}}
    @if (trim($slot) !== '')
        {{ $slot }}
    @endif
</div>
