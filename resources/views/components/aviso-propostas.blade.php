@props(['propostas'])

{{-- Mês com duas ou mais propostas e nenhuma publicada: no lugar da lista de
     plantão fica só este aviso, para nenhuma proposta chegar às unidades
     antes da decisão. --}}
@php
    $primeira = $propostas->first();
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900']) }}>
    <div class="flex items-start gap-2.5">
        <x-icone nome="alerta" class="mt-0.5 size-5 shrink-0 text-amber-600" />
        <div class="min-w-0">
            <p class="font-semibold">
                {{ ucfirst($primeira->primeiroDia()->translatedFormat('F')) }} tem {{ $propostas->count() }} propostas e nenhuma foi publicada.
            </p>
            <p class="mt-1 text-amber-800">
                Enquanto uma delas não for publicada, a lista de plantão deste mês não aparece nem é enviada às unidades.
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
                <x-botao href="{{ route('escalas.comparar', $primeira) }}" variante="secundario" tamanho="pequeno" icone="grade">
                    Comparar as propostas
                </x-botao>
                @foreach ($propostas as $proposta)
                    <x-botao href="{{ route('escalas.show', $proposta) }}" variante="texto" tamanho="pequeno">
                        {{ $proposta->nome ?? 'Abrir' }}
                    </x-botao>
                @endforeach
            </div>
        </div>
    </div>
</div>
