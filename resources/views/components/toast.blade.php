{{--
    Avisos flutuantes disparados pelos componentes Livewire:
    $this->dispatch('aviso', tipo: 'sucesso', texto: '...')
--}}

<div
    x-data="{
        avisos: [],
        proximoId: 1,
        adicionar(detalhe) {
            const id = this.proximoId++;
            this.avisos.push({ id, tipo: detalhe.tipo ?? 'sucesso', texto: detalhe.texto ?? '' });
            // Erros ficam mais tempo na tela, pois costumam exigir leitura.
            setTimeout(() => this.remover(id), detalhe.tipo === 'erro' ? 8000 : 4500);
        },
        remover(id) {
            this.avisos = this.avisos.filter((a) => a.id !== id);
        },
    }"
    @aviso.window="adicionar($event.detail)"
    class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:bottom-auto sm:right-0 sm:top-16 sm:items-end nao-imprimir"
    aria-live="polite"
    aria-atomic="false"
>
    <template x-for="aviso in avisos" :key="aviso.id">
        <div
            x-transition.duration.200ms
            class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border px-3.5 py-2.5 text-sm shadow-lg"
            :class="{
                'border-emerald-200 bg-emerald-50 text-emerald-800': aviso.tipo === 'sucesso',
                'border-amber-200 bg-amber-50 text-amber-800': aviso.tipo === 'atencao',
                'border-rose-200 bg-rose-50 text-rose-800': aviso.tipo === 'erro',
            }"
        >
            <p class="flex-1" x-text="aviso.texto"></p>
            <button
                type="button"
                @click="remover(aviso.id)"
                class="shrink-0 rounded p-0.5 opacity-60 transition hover:opacity-100"
            >
                <x-icone nome="fechar" class="size-4" />
                <span class="sr-only">Fechar aviso</span>
            </button>
        </div>
    </template>
</div>
