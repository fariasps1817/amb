@props([
    'rotulo' => 'Telefone',
    'numero',
])

@php
    use App\Support\Telefone;

    $formatado = Telefone::formatar($numero);
    $whatsapp = Telefone::linkWhatsapp($numero);
    $botao = 'flex size-11 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50';
@endphp

{{-- Campo de leitura com os atalhos no lugar do número, para usar dentro de um <dl>. --}}
<div>
    <dt class="text-xs text-slate-500">{{ $rotulo }}</dt>
    <dd class="mt-1 flex items-center gap-2">
        <span class="mr-1 text-sm text-slate-900">{{ $formatado }}</span>

        <a href="tel:{{ Telefone::digitos($numero) }}" title="Ligar" aria-label="Ligar para {{ $formatado }}" class="{{ $botao }} text-marca-700">
            <x-icone nome="telefone" class="size-5" />
        </a>

        @if ($whatsapp)
            <a href="{{ $whatsapp }}" target="_blank" rel="noopener" title="Abrir no WhatsApp" aria-label="Abrir {{ $formatado }} no WhatsApp" class="{{ $botao }} text-emerald-600">
                <x-icone nome="whatsapp-marca" class="size-5 fill-current stroke-none" />
            </a>
        @endif
    </dd>
</div>
