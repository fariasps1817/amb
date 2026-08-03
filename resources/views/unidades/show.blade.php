@php $regime = $unidade->regime(); @endphp

<x-layouts.app :titulo="$unidade->sigla" :subtitulo="$unidade->nome">
    <x-slot:acoes>
        <x-botao href="{{ route('unidades.index') }}" variante="secundario" tamanho="pequeno" icone="seta-esquerda">
            <span class="hidden sm:inline">Voltar</span>
        </x-botao>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('unidades.edit', $unidade) }}" tamanho="pequeno" icone="lapis">
                <span class="hidden sm:inline">Editar</span>
            </x-botao>
        @endif
    </x-slot:acoes>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-cartao titulo="Dados da unidade">
                <x-slot:acoes>
                    <x-badge :cor="$unidade->ativo ? 'bg-emerald-100 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">
                        {{ $unidade->ativo ? 'Ativa' : 'Inativa' }}
                    </x-badge>
                </x-slot:acoes>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-3.5 sm:grid-cols-3">
                    <x-campo-leitura rotulo="Tipo" :valor="$unidade->tipo" />
                    <x-campo-leitura rotulo="Ordem de impressão" :valor="$unidade->ordem" />
                    <x-campo-leitura rotulo="Responsável" :valor="$unidade->responsavel" :complemento="$unidade->cargo_responsavel" />
                    <div class="col-span-2 sm:col-span-3">
                        <x-campo-leitura rotulo="Endereço" :valor="$unidade->enderecoCompleto()" />
                    </div>
                    <x-campo-leitura rotulo="Telefones" :valor="$unidade->telefonesFormatados()" />
                    <x-campo-leitura rotulo="E-mail" :valor="$unidade->email" />
                </dl>

                @if ($unidade->observacao)
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="text-xs font-medium text-slate-500">Observação</p>
                        <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $unidade->observacao }}</p>
                    </div>
                @endif
            </x-cartao>

            <x-cartao titulo="Frota vinculada" :descricao="$unidade->ambulancias->count().' ambulância(s)'">
                <x-slot:acoes>
                    @if (auth()->user()->podeEditar())
                        <x-botao href="{{ route('ambulancias.create') }}" variante="secundario" tamanho="pequeno" icone="mais">
                            Adicionar
                        </x-botao>
                    @endif
                </x-slot:acoes>

                @forelse ($unidade->ambulancias as $ambulancia)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 py-2.5 first:pt-0 last:border-0">
                        <div class="min-w-0">
                            <a href="{{ route('ambulancias.show', $ambulancia) }}" class="text-sm font-semibold text-slate-900 hover:text-marca-700 tabular-nums">
                                {{ $ambulancia->placaFormatada() }}
                            </a>
                            <p class="truncate text-xs text-slate-500">
                                {{ $ambulancia->identificacao ? $ambulancia->identificacao.' · ' : '' }}{{ $ambulancia->marcaModelo() }}
                                @if ($ambulancia->anos()) · {{ $ambulancia->anos() }} @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-badge>{{ $ambulancia->vinculo->rotulo() }}</x-badge>
                            @unless ($ambulancia->ativo)
                                <x-badge cor="bg-slate-100 text-slate-600 ring-slate-200">inativa</x-badge>
                            @endunless
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">
                        Nenhuma ambulância vinculada a esta unidade.
                    </p>
                @endforelse
            </x-cartao>
        </div>

        {{-- Dimensionamento do efetivo desta unidade --}}
        <div>
            <x-cartao titulo="Dimensionamento">
                <div class="rounded-lg bg-marca-50 p-3 ring-1 ring-inset ring-marca-200">
                    <p class="text-xs text-marca-700">Regime de plantão</p>
                    <p class="text-2xl font-semibold text-marca-900">{{ $regime->notacao() }}</p>
                    <p class="mt-1 text-xs text-marca-700">
                        {{ $regime->horasTrabalho }}h de plantão · {{ $regime->horasDescanso }}h de descanso
                    </p>
                </div>

                @php $frotaAtiva = $unidade->ambulancias->where('ativo', true)->count(); @endphp

                <dl class="mt-4 space-y-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-sm text-slate-600">Motoristas por ambulância</dt>
                        <dd class="text-lg font-semibold text-slate-900 tabular-nums">{{ $regime->motoristasNecessarios() }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-2">
                        <dt class="text-sm text-slate-600">Ambulâncias ativas</dt>
                        <dd class="text-lg font-semibold text-slate-900 tabular-nums">{{ $frotaAtiva }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-2 border-t border-slate-200 pt-3">
                        <dt class="text-sm font-medium text-slate-700">Total de motoristas</dt>
                        <dd class="text-2xl font-semibold text-marca-700 tabular-nums">
                            {{ $frotaAtiva * $regime->motoristasNecessarios() }}
                        </dd>
                    </div>
                </dl>

                <p class="mt-3 text-xs text-slate-500">
                    Cada motorista assume um dia do ciclo e retorna após {{ $regime->intervaloEmDias() }} dias.
                </p>
            </x-cartao>
        </div>
    </div>
</x-layouts.app>
