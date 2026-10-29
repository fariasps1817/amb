<div>
    @if ($aberto)
        <section
            class="rounded-xl bg-white shadow-sm ring-2 ring-marca-300"
            x-data
            x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })"
        >
            <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-slate-900">Ordem das ambulâncias</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Toque nas setas para subir ou descer. A planilha impressa e a lista de quem está de plantão seguem esta ordem.
                    </p>
                </div>
                <x-botao wire:click="concluir" tamanho="pequeno" icone="check">Concluir</x-botao>
            </header>

            <ol class="divide-y divide-slate-100">
                @foreach ($this->postos as $posto)
                    <li wire:key="ordem-{{ $posto->id }}" class="flex items-center gap-3 px-4 py-2 sm:px-5">
                        <span class="w-5 shrink-0 text-right text-xs text-slate-400 tabular-nums">{{ $loop->iteration }}</span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $posto->rotuloLotacao() }}</p>
                            <p class="truncate text-xs text-slate-500">
                                {{ $posto->rotuloPlaca() }} · {{ $posto->unidade?->nome }}
                            </p>
                        </div>

                        {{-- Botoes de 40px: o alvo de toque confortavel no celular. --}}
                        <div class="flex shrink-0 gap-1.5">
                            <button
                                type="button"
                                wire:click="mover({{ $posto->id }}, -1)"
                                wire:loading.attr="disabled"
                                @disabled($loop->first)
                                class="flex size-10 items-center justify-center rounded-lg text-slate-600 ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50 disabled:opacity-30"
                                title="Subir"
                            >
                                <x-icone nome="seta-cima" class="size-5" />
                                <span class="sr-only">Subir {{ $posto->rotuloLotacao() }}</span>
                            </button>
                            <button
                                type="button"
                                wire:click="mover({{ $posto->id }}, 1)"
                                wire:loading.attr="disabled"
                                @disabled($loop->last)
                                class="flex size-10 items-center justify-center rounded-lg text-slate-600 ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50 disabled:opacity-30"
                                title="Descer"
                            >
                                <x-icone nome="seta-baixo" class="size-5" />
                                <span class="sr-only">Descer {{ $posto->rotuloLotacao() }}</span>
                            </button>
                        </div>
                    </li>
                @endforeach
            </ol>

            @if ($alterou && $recarregar !== null)
                <p class="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 sm:px-5">
                    A planilha abaixo é atualizada quando você tocar em <strong>Concluir</strong>.
                </p>
            @endif
        </section>
    @endif
</div>
