@php
    $telefones = $config->telefonesFormatados();
@endphp

{{-- De propósito sem dados do paciente: basta o protocolo para falar com a coordenação. --}}
<x-layouts.visitante titulo="Solicitação recebida">
    <div class="mx-auto w-full max-w-md">
        <div class="rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-200">
            <span class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100">
                <x-icone nome="check" class="size-8 text-emerald-600" />
            </span>

            <h1 class="mt-4 text-xl font-semibold text-slate-900">Solicitação recebida</h1>

            <p class="mt-4 text-sm text-slate-600">Número do protocolo</p>
            <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ $solicitacao->protocolo }}</p>
            <p class="mt-1 text-xs text-slate-500">Recebida em {{ $solicitacao->solicitada_em->format('d/m/Y \à\s H:i') }} (horário de Fortaleza)</p>

            <p class="mt-5 rounded-lg bg-sky-50 px-3 py-2.5 text-sm text-sky-900 ring-1 ring-inset ring-sky-200">
                A coordenação vai ligar para confirmar. <strong>Guarde o protocolo</strong> para falar sobre este pedido.
            </p>

            @if ($telefones !== '')
                <p class="mt-4 text-sm text-slate-600">
                    Se precisar falar com a coordenação:<br>
                    <span class="font-semibold text-slate-900">{{ $telefones }}</span>
                </p>
            @endif

            <x-botao href="{{ route('solicitacao.formulario') }}" variante="secundario" tamanho="grande" class="mt-5 w-full">
                Fazer outra solicitação
            </x-botao>
        </div>
    </div>
</x-layouts.visitante>
