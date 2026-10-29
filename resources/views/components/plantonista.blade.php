@props(['plantao', 'horario' => false])

{{-- Uma linha de "quem está de plantão": usada no painel e na tela do plantão do dia. --}}
@php
    $motorista = $plantao->motorista;
    $posto = $plantao->posto;
@endphp

<div class="flex items-center gap-3 py-2.5">
    <span class="flex h-11 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-marca-50 px-1 text-center text-[0.6rem] font-bold leading-tight break-words text-marca-800 ring-1 ring-marca-100">
        {{ Str::limit($posto?->rotuloLotacao() ?? '', 10, '') }}
    </span>

    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-semibold text-slate-900">{{ $motorista?->nome_curto ?? 'Sem motorista' }}</p>
        <p class="truncate text-xs text-slate-500">
            {{-- O número aparece por extenso: muitas vezes ele é lido em voz
                 alta para quem ligou perguntando quem está na ambulância. --}}
            @if ($motorista?->telefone_1)
                <span class="font-medium text-slate-700 tabular-nums">{{ $motorista->telefoneFormatado() }}</span> ·
            @endif
            {{ $posto?->rotuloPlaca() }}
            @if ($posto?->unidade) <span class="hidden sm:inline">· {{ $posto->unidade->nome }}</span> @endif
            @if ($horario) · {{ $plantao->horaEntradaTexto() }} às {{ $plantao->horaSaidaTexto() }} @endif
        </p>
    </div>

    @if ($motorista?->telefone_1)
        <a
            href="tel:{{ \App\Support\Telefone::digitos($motorista->telefone_1) }}"
            class="flex size-11 shrink-0 items-center justify-center rounded-lg text-marca-700 ring-1 ring-inset ring-marca-200 transition hover:bg-marca-50"
            title="Ligar para {{ $motorista->nome_curto }}"
        >
            <x-icone nome="telefone" class="size-5" />
            <span class="sr-only">Ligar para {{ $motorista->nome_curto }}</span>
        </a>
    @endif
</div>
