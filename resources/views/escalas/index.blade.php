<x-layouts.app titulo="Escalas mensais">
    <x-slot:acoes>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('escalas.create') }}" icone="mais" tamanho="pequeno">
                Nova escala
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
                                @if ($escala->nome)
                                    <span class="text-sm font-medium text-marca-700">{{ $escala->nome }}</span>
                                @endif
                                <x-badge :cor="$escala->status->corBadge()">{{ $escala->status->rotulo() }}</x-badge>
                            </div>
                            @if (($porMes[$escala->ano * 100 + $escala->mes] ?? 1) > 1 && $escala->ehRascunho())
                                <a href="{{ route('escalas.comparar', $escala) }}" class="mt-0.5 inline-block text-xs font-medium text-amber-700 hover:underline">
                                    {{ $porMes[$escala->ano * 100 + $escala->mes] }} propostas para este mês — comparar
                                </a>
                            @endif
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

                        {{-- No celular os botoes descem para uma linha propria, com
                             o nome escrito, em vez de espremer o titulo do mes. --}}
                        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0">
                            <x-botao href="{{ route('escalas.show', $escala) }}" variante="suave" icone="olho" class="flex-auto sm:flex-none">
                                Abrir
                            </x-botao>
                            @if ($escala->editavel() && auth()->user()->podeEditar())
                                <x-botao href="{{ route('escalas.montar', $escala) }}" variante="secundario" icone="grade" class="flex-auto sm:flex-none">
                                    Montar
                                </x-botao>
                            @endif
                            <x-botao href="{{ route('documentos.index', $escala) }}" variante="secundario" icone="documentos" class="flex-auto sm:flex-none">
                                Documentos
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
