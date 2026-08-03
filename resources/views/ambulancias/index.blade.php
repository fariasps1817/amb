<x-layouts.app titulo="Ambulâncias" subtitulo="Frota do setor">
    <x-slot:acoes>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('ambulancias.create') }}" icone="mais" tamanho="pequeno">
                <span class="hidden sm:inline">Nova ambulância</span>
                <span class="sm:hidden">Nova</span>
            </x-botao>
        @endif
    </x-slot:acoes>

    <div class="space-y-4">
        <form method="GET" class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200 sm:p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-input
                    name="busca"
                    value="{{ request('busca') }}"
                    placeholder="Placa, RENAVAM ou modelo"
                    aria-label="Buscar ambulância"
                />
                <x-select name="unidade_id" :opcoes="$unidades->all()" :selecionado="request('unidade_id')" vazio="Todas as unidades" />
                <x-select name="vinculo" :opcoes="\App\Enums\VinculoAmbulancia::opcoes()" :selecionado="request('vinculo')" vazio="Todos os vínculos" />
                <div class="flex gap-2">
                    <x-select name="ativo" :opcoes="['1' => 'Ativas', '0' => 'Inativas']" :selecionado="request('ativo')" vazio="Todas" />
                    <x-botao type="submit" variante="secundario" icone="busca">
                        <span class="sr-only">Filtrar</span>
                    </x-botao>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="hidden overflow-x-auto sm:block scrollbar-fina">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Placa</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Identificação</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Veículo</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Unidade padrão</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Vínculo</th>
                            <th scope="col" class="px-4 py-2.5"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($ambulancias as $ambulancia)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('ambulancias.show', $ambulancia) }}" class="font-semibold text-slate-900 hover:text-marca-700 tabular-nums">
                                        {{ $ambulancia->placaFormatada() }}
                                    </a>
                                    @if ($ambulancia->renavam)
                                        <p class="text-xs text-slate-500 tabular-nums">RENAVAM {{ $ambulancia->renavam }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-slate-700">{{ $ambulancia->identificacao ?: '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <p class="text-slate-700">{{ $ambulancia->marcaModelo() ?: '—' }}</p>
                                    <p class="text-xs text-slate-500 tabular-nums">
                                        {{ $ambulancia->anos() }}{{ $ambulancia->tipo ? ' · '.$ambulancia->tipo : '' }}
                                    </p>
                                </td>
                                <td class="px-4 py-2.5">
                                    @if ($ambulancia->unidade)
                                        <a href="{{ route('unidades.show', $ambulancia->unidade) }}" class="text-slate-700 hover:text-marca-700">
                                            {{ $ambulancia->unidade->sigla }}
                                        </a>
                                        <p class="text-xs text-slate-500">{{ $ambulancia->unidade->regimeNotacao() }}</p>
                                    @else
                                        <span class="text-xs text-amber-600">sem lotação</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-badge>{{ $ambulancia->vinculo->rotulo() }}</x-badge>
                                        @unless ($ambulancia->ativo)
                                            <x-badge cor="bg-slate-100 text-slate-600 ring-slate-200">inativa</x-badge>
                                        @endunless
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if (auth()->user()->podeEditar())
                                        <x-botao href="{{ route('ambulancias.edit', $ambulancia) }}" variante="texto" tamanho="pequeno" icone="lapis">
                                            <span class="sr-only">Editar {{ $ambulancia->placaFormatada() }}</span>
                                        </x-botao>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                                    Nenhuma ambulância encontrada.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Celular --}}
            <ul class="divide-y divide-slate-100 sm:hidden">
                @forelse ($ambulancias as $ambulancia)
                    <li class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('ambulancias.show', $ambulancia) }}" class="font-semibold text-slate-900 tabular-nums">
                                    {{ $ambulancia->placaFormatada() }}
                                </a>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $ambulancia->identificacao ? $ambulancia->identificacao.' · ' : '' }}{{ $ambulancia->marcaModelo() }}
                                </p>
                            </div>
                            <x-badge>{{ $ambulancia->vinculo->rotulo() }}</x-badge>
                        </div>

                        <dl class="mt-2.5 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <dt class="text-slate-500">Unidade</dt>
                                <dd class="text-slate-700">{{ $ambulancia->unidade?->sigla ?: 'sem lotação' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Ano</dt>
                                <dd class="text-slate-700 tabular-nums">{{ $ambulancia->anos() ?: '—' }}</dd>
                            </div>
                        </dl>

                        @if (auth()->user()->podeEditar())
                            <x-botao href="{{ route('ambulancias.edit', $ambulancia) }}" variante="secundario" tamanho="pequeno" icone="lapis" class="mt-3 w-full">
                                Editar
                            </x-botao>
                        @endif
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">Nenhuma ambulância encontrada.</li>
                @endforelse
            </ul>
        </div>

        @if ($ambulancias->hasPages())
            <div>{{ $ambulancias->links() }}</div>
        @endif
    </div>
</x-layouts.app>
