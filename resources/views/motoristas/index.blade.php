<x-layouts.app titulo="Motoristas" :subtitulo="$totais['ativos'].' ativos · '.$totais['inativos'].' inativos'">
    <x-slot:acoes>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('motoristas.create') }}" icone="mais" tamanho="pequeno">
                <span class="hidden sm:inline">Novo motorista</span>
                <span class="sm:hidden">Novo</span>
            </x-botao>
        @endif
    </x-slot:acoes>

    <div class="space-y-4">

        {{-- Filtros --}}
        <form method="GET" class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200 sm:p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <x-input
                        name="busca"
                        value="{{ request('busca') }}"
                        placeholder="Buscar por nome, CPF, matrícula ou telefone"
                        aria-label="Buscar motorista"
                    />
                </div>

                <x-select
                    name="status"
                    :opcoes="\App\Enums\StatusMotorista::opcoes()"
                    :selecionado="request('status')"
                    vazio="Todas as situações"
                />

                <x-select
                    name="vinculo"
                    :opcoes="\App\Enums\Vinculo::opcoes()"
                    :selecionado="request('vinculo')"
                    vazio="Todos os vínculos"
                />
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        type="checkbox"
                        name="irregulares"
                        value="1"
                        class="size-4 rounded border-slate-300 text-marca-600 focus:ring-marca-600"
                        @checked(request()->boolean('irregulares'))
                    >
                    Somente com pendências (CNH, contrato ou telefone)
                </label>

                <div class="flex gap-2">
                    @if (request()->hasAny(['busca', 'status', 'vinculo', 'irregulares']))
                        <x-botao href="{{ route('motoristas.index') }}" variante="texto" tamanho="pequeno">
                            Limpar
                        </x-botao>
                    @endif
                    <x-botao type="submit" variante="secundario" tamanho="pequeno" icone="busca">Filtrar</x-botao>
                </div>
            </div>
        </form>

        {{-- Lista --}}
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">

            {{-- Tabela em telas médias e grandes --}}
            <div class="hidden overflow-x-auto sm:block scrollbar-fina">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Motorista</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Vínculo</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">CNH</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Contato</th>
                            <th scope="col" class="px-4 py-2.5 font-semibold">Situação</th>
                            <th scope="col" class="px-4 py-2.5"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($motoristas as $motorista)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('motoristas.show', $motorista) }}" class="font-medium text-slate-900 hover:text-marca-700">
                                        {{ $motorista->nome_curto }}
                                    </a>
                                    <p class="text-xs text-slate-500">{{ $motorista->nome_completo }}</p>
                                </td>

                                <td class="px-4 py-2.5">
                                    <p class="text-slate-700">{{ $motorista->vinculo->rotulo() }}</p>
                                    @if ($motorista->vinculo_fim)
                                        <p class="text-xs {{ $motorista->contratoEncerrado() ? 'text-rose-600' : ($motorista->contratoVencendo() ? 'text-amber-600' : 'text-slate-500') }}">
                                            até {{ $motorista->vinculo_fim->format('d/m/Y') }}
                                        </p>
                                    @endif
                                </td>

                                <td class="px-4 py-2.5">
                                    <p class="text-slate-700">{{ $motorista->cnh_categoria ?: '—' }}</p>
                                    @if ($motorista->cnh_validade)
                                        <p class="text-xs tabular-nums {{ $motorista->cnhVencida() ? 'font-medium text-rose-600' : ($motorista->cnhVencendo() ? 'text-amber-600' : 'text-slate-500') }}">
                                            {{ $motorista->cnhVencida() ? 'vencida em ' : 'até ' }}{{ $motorista->cnh_validade->format('d/m/Y') }}
                                        </p>
                                    @endif
                                </td>

                                <td class="px-4 py-2.5">
                                    @if ($motorista->telefone_1)
                                        <a href="tel:{{ \App\Support\Telefone::digitos($motorista->telefone_1) }}" class="text-slate-700 hover:text-marca-700 tabular-nums">
                                            {{ $motorista->telefoneFormatado() }}
                                        </a>
                                    @else
                                        <span class="text-xs text-rose-600">sem telefone</span>
                                    @endif
                                </td>

                                <td class="px-4 py-2.5">
                                    <x-badge :cor="$motorista->status->corBadge()">{{ $motorista->status->rotulo() }}</x-badge>
                                </td>

                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    @if (auth()->user()->podeEditar())
                                        <x-botao href="{{ route('motoristas.edit', $motorista) }}" variante="texto" tamanho="pequeno" icone="lapis">
                                            <span class="sr-only">Editar {{ $motorista->nome_curto }}</span>
                                        </x-botao>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                                    Nenhum motorista encontrado com os filtros aplicados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Cartões no celular --}}
            <ul class="divide-y divide-slate-100 sm:hidden">
                @forelse ($motoristas as $motorista)
                    <li class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('motoristas.show', $motorista) }}" class="block truncate font-medium text-slate-900">
                                    {{ $motorista->nome_curto }}
                                </a>
                                <p class="truncate text-xs text-slate-500">{{ $motorista->nome_completo }}</p>
                            </div>
                            <x-badge :cor="$motorista->status->corBadge()">{{ $motorista->status->rotulo() }}</x-badge>
                        </div>

                        <dl class="mt-2.5 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <dt class="text-slate-500">Vínculo</dt>
                                <dd class="text-slate-700">{{ $motorista->vinculo->rotulo() }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">CNH</dt>
                                <dd class="{{ $motorista->cnhVencida() ? 'font-medium text-rose-600' : 'text-slate-700' }}">
                                    {{ $motorista->cnh_categoria ?: '—' }}
                                    @if ($motorista->cnh_validade)
                                        · {{ $motorista->cnh_validade->format('m/Y') }}
                                    @endif
                                </dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-slate-500">Contato</dt>
                                <dd>
                                    @if ($motorista->telefone_1)
                                        <a href="tel:{{ \App\Support\Telefone::digitos($motorista->telefone_1) }}" class="text-marca-700 tabular-nums">
                                            {{ $motorista->telefoneFormatado() }}
                                        </a>
                                    @else
                                        <span class="text-rose-600">sem telefone</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if (auth()->user()->podeEditar())
                            <div class="mt-3 flex gap-2">
                                <x-botao href="{{ route('motoristas.edit', $motorista) }}" variante="secundario" tamanho="pequeno" icone="lapis" class="flex-1">
                                    Editar
                                </x-botao>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">
                        Nenhum motorista encontrado.
                    </li>
                @endforelse
            </ul>
        </div>

        @if ($motoristas->hasPages())
            <div>{{ $motoristas->links() }}</div>
        @endif
    </div>
</x-layouts.app>
