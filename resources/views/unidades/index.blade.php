<x-layouts.app titulo="Unidades" subtitulo="Lotação e referência das ambulâncias">
    <x-slot:acoes>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('unidades.create') }}" icone="mais" tamanho="pequeno">
                <span class="hidden sm:inline">Nova unidade</span>
                <span class="sm:hidden">Nova</span>
            </x-botao>
        @endif
    </x-slot:acoes>

    <div class="space-y-4">
        <form method="GET" class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200 sm:p-4">
            <div class="flex flex-col gap-3 sm:flex-row">
                <div class="flex-1">
                    <x-input
                        name="busca"
                        value="{{ request('busca') }}"
                        placeholder="Buscar por nome, sigla, bairro ou responsável"
                        aria-label="Buscar unidade"
                    />
                </div>
                <x-select
                    name="ativo"
                    :opcoes="['1' => 'Somente ativas', '0' => 'Somente inativas']"
                    :selecionado="request('ativo')"
                    vazio="Todas"
                    class="sm:w-48"
                />
                <x-botao type="submit" variante="secundario" icone="busca">Filtrar</x-botao>
            </div>
        </form>

        {{-- Cada cartão mostra o regime, que é o dado que define quantos
             motoristas cada ambulância da unidade precisa. --}}
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($unidades as $unidade)
                @php $regime = $unidade->regime(); @endphp

                <div class="flex flex-col rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('unidades.show', $unidade) }}" class="truncate font-semibold text-slate-900 hover:text-marca-700">
                                    {{ $unidade->sigla }}
                                </a>
                                @unless ($unidade->ativo)
                                    <x-badge cor="bg-slate-100 text-slate-600 ring-slate-200">inativa</x-badge>
                                @endunless
                            </div>
                            <p class="truncate text-sm text-slate-600">{{ $unidade->nome }}</p>
                        </div>

                        @if (auth()->user()->podeEditar())
                            <x-botao href="{{ route('unidades.edit', $unidade) }}" variante="texto" tamanho="pequeno" icone="lapis">
                                <span class="sr-only">Editar {{ $unidade->sigla }}</span>
                            </x-botao>
                        @endif
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <x-badge cor="bg-marca-50 text-marca-800 ring-marca-200">
                            Regime {{ $regime->notacao() }}
                        </x-badge>
                        <span class="text-xs text-slate-500">
                            {{ $regime->motoristasNecessarios() }} motoristas por ambulância
                        </span>
                    </div>

                    <dl class="mt-3 space-y-1.5 text-xs">
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Frota ativa</dt>
                            <dd class="font-medium text-slate-800 tabular-nums">{{ $unidade->ambulancias_count }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-slate-500">Motoristas necessários</dt>
                            <dd class="font-medium text-slate-800 tabular-nums">
                                {{ $unidade->ambulancias_count * $regime->motoristasNecessarios() }}
                            </dd>
                        </div>
                        @if ($unidade->bairro)
                            <div class="flex justify-between gap-2">
                                <dt class="text-slate-500">Bairro</dt>
                                <dd class="truncate text-slate-800">{{ $unidade->bairro }}</dd>
                            </div>
                        @endif
                        @if ($unidade->responsavel)
                            <div class="flex justify-between gap-2">
                                <dt class="text-slate-500">Responsável</dt>
                                <dd class="truncate text-slate-800">{{ $unidade->responsavel }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($unidade->telefone_1)
                        <a
                            href="tel:{{ \App\Support\Telefone::digitos($unidade->telefone_1) }}"
                            class="mt-3 text-xs font-medium text-marca-700 hover:underline tabular-nums"
                        >
                            {{ $unidade->telefoneFormatado() }}
                        </a>
                    @endif
                </div>
            @empty
                <div class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200 sm:col-span-2 xl:col-span-3">
                    <span class="mx-auto flex size-11 items-center justify-center rounded-full bg-slate-100">
                        <x-icone nome="unidades" class="size-6 text-slate-400" />
                    </span>
                    <h3 class="mt-3 text-sm font-semibold text-slate-900">Nenhuma unidade cadastrada</h3>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                        Cadastre a UPA, os postos de saúde e a sede. O regime de plantão informado aqui define
                        quantos motoristas cada ambulância da unidade vai precisar.
                    </p>
                    @if (auth()->user()->podeEditar())
                        <x-botao href="{{ route('unidades.create') }}" icone="mais" class="mt-4">Cadastrar unidade</x-botao>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($unidades->hasPages())
            <div>{{ $unidades->links() }}</div>
        @endif
    </div>
</x-layouts.app>
