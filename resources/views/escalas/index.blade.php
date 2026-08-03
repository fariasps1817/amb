<x-layouts.app titulo="Escalas mensais">
    <x-slot:acoes>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('escalas.create') }}" icone="mais" tamanho="pequeno">
                <span class="hidden sm:inline">Nova escala</span>
                <span class="sm:hidden">Nova</span>
            </x-botao>
        @endif
    </x-slot:acoes>

    <div class="space-y-4">
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <ul class="divide-y divide-slate-100">
                @forelse ($escalas as $escala)
                    <li class="flex flex-wrap items-center gap-3 p-4">
                        <div class="w-14 shrink-0 rounded-lg bg-marca-50 py-1.5 text-center ring-1 ring-marca-100">
                            <p class="text-[0.65rem] font-semibold uppercase text-marca-600">
                                {{ $escala->primeiroDia()->translatedFormat('M') }}
                            </p>
                            <p class="text-sm font-bold text-marca-900 tabular-nums">{{ $escala->ano }}</p>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('escalas.show', $escala) }}" class="font-medium text-slate-900 hover:text-marca-700">
                                    {{ $escala->referenciaLonga() }}
                                </a>
                                <x-badge :cor="$escala->status->corBadge()">{{ $escala->status->rotulo() }}</x-badge>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ $escala->postos_count }} ambulância(s) ·
                                {{ $escala->plantoes_count }} plantão(ões)
                                @if ($escala->publicada_em)
                                    · publicada em {{ $escala->publicada_em->format('d/m/Y') }}
                                @elseif ($escala->gerada_em)
                                    · gerada em {{ $escala->gerada_em->format('d/m/Y H:i') }}
                                @else
                                    · em montagem
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($escala->editavel() && auth()->user()->podeEditar())
                                <x-botao href="{{ route('escalas.montar', $escala) }}" variante="secundario" tamanho="pequeno" icone="grade">
                                    <span class="hidden sm:inline">Montar</span>
                                </x-botao>
                            @endif
                            <x-botao href="{{ route('documentos.index', $escala) }}" variante="secundario" tamanho="pequeno" icone="documentos">
                                <span class="hidden sm:inline">Documentos</span>
                            </x-botao>
                            <x-botao href="{{ route('escalas.show', $escala) }}" variante="texto" tamanho="pequeno" icone="seta-direita">
                                <span class="sr-only">Abrir escala de {{ $escala->referenciaLonga() }}</span>
                            </x-botao>
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center">
                        <span class="mx-auto flex size-11 items-center justify-center rounded-full bg-slate-100">
                            <x-icone nome="escalas" class="size-6 text-slate-400" />
                        </span>
                        <h3 class="mt-3 text-sm font-semibold text-slate-900">Nenhuma escala cadastrada</h3>
                        <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                            Antes de montar a primeira escala, confirme se as unidades têm o regime de plantão
                            correto e se as ambulâncias estão vinculadas às unidades.
                        </p>
                        @if (auth()->user()->podeEditar())
                            <x-botao href="{{ route('escalas.create') }}" icone="mais" class="mt-4">Montar primeira escala</x-botao>
                        @endif
                    </li>
                @endforelse
            </ul>
        </div>

        @if ($escalas->hasPages())
            <div>{{ $escalas->links() }}</div>
        @endif
    </div>
</x-layouts.app>
