<x-layouts.app :titulo="$ambulancia->placaFormatada()" :subtitulo="$ambulancia->marcaModelo() ?: 'Ambulância'">
    <x-slot:acoes>
        <x-botao href="{{ route('ambulancias.index') }}" variante="secundario" tamanho="pequeno" icone="seta-esquerda">
            <span class="hidden sm:inline">Voltar</span>
        </x-botao>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('ambulancias.edit', $ambulancia) }}" tamanho="pequeno" icone="lapis">
                <span class="hidden sm:inline">Editar</span>
            </x-botao>
        @endif
    </x-slot:acoes>

    <div class="mx-auto max-w-4xl space-y-5">
        <x-cartao titulo="Dados do veículo">
            <x-slot:acoes>
                <x-badge>{{ $ambulancia->vinculo->rotulo() }}</x-badge>
                <x-badge :cor="$ambulancia->ativo ? 'bg-emerald-100 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">
                    {{ $ambulancia->ativo ? 'Ativa' : 'Inativa' }}
                </x-badge>
            </x-slot:acoes>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3.5 sm:grid-cols-4">
                <x-campo-leitura rotulo="Placa" :valor="$ambulancia->placaFormatada()" />
                <x-campo-leitura rotulo="RENAVAM" :valor="$ambulancia->renavam" />
                <x-campo-leitura rotulo="Marca" :valor="$ambulancia->marca" />
                <x-campo-leitura rotulo="Modelo" :valor="$ambulancia->modelo" />
                <x-campo-leitura rotulo="Fabricação / Modelo" :valor="$ambulancia->anos()" :complemento="$ambulancia->idade() !== null ? $ambulancia->idade().' anos' : null" />
                <x-campo-leitura rotulo="Tipo" :valor="$ambulancia->tipo" />
                <x-campo-leitura rotulo="Identificação" :valor="$ambulancia->identificacao" />
                <x-campo-leitura
                    rotulo="Unidade padrão"
                    :valor="$ambulancia->unidade?->sigla"
                    :complemento="$ambulancia->unidade?->regimeNotacao()"
                />
            </dl>

            @if ($ambulancia->observacao)
                <div class="mt-4 border-t border-slate-100 pt-3">
                    <p class="text-xs font-medium text-slate-500">Observação</p>
                    <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $ambulancia->observacao }}</p>
                </div>
            @endif
        </x-cartao>

        {{-- Histórico: em que unidade o veículo operou e com qual equipe --}}
        <x-cartao titulo="Histórico nas escalas" descricao="Últimos 12 meses">
            @forelse ($postos as $posto)
                <div class="border-b border-slate-100 py-3 first:pt-0 last:border-0">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <a href="{{ route('escalas.show', $posto->escala) }}" class="text-sm font-medium text-slate-900 hover:text-marca-700">
                            {{ $posto->escala->referenciaLonga() }}
                        </a>
                        <div class="flex items-center gap-2">
                            <x-badge cor="bg-marca-50 text-marca-800 ring-marca-200">{{ $posto->regimeNotacao() }}</x-badge>
                            <x-badge>{{ $posto->rotuloLotacao() }}</x-badge>
                        </div>
                    </div>

                    <p class="mt-1 text-xs text-slate-500">
                        {{ $posto->unidade?->nome }} ·
                        {{ $posto->vagasOcupadas() }}/{{ $posto->vagas() }} motoristas
                    </p>

                    @if ($posto->lotacoes->isNotEmpty())
                        <p class="mt-1 text-xs text-slate-600">
                            {{ $posto->lotacoes->sortBy('posicao')->map(fn ($l) => $l->posicao.'. '.$l->motorista?->nome_curto)->implode(' · ') }}
                        </p>
                    @endif
                </div>
            @empty
                <p class="py-6 text-center text-sm text-slate-500">
                    Esta ambulância ainda não foi incluída em nenhuma escala.
                </p>
            @endforelse
        </x-cartao>
    </div>
</x-layouts.app>
