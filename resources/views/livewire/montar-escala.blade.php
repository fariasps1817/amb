<div
    class="space-y-5"
    x-data
    @aviso.window="$dispatch('mostrar-aviso', $event.detail)"
>
    {{-- ------------------------------------------------------------
         Resumo do dimensionamento
         ------------------------------------------------------------ --}}

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-indicador rotulo="Ambulâncias" :valor="$this->resumo['postos']" icone="ambulancias" />
        <x-indicador
            rotulo="Vagas preenchidas"
            :valor="$this->resumo['vagas_ocupadas'].'/'.$this->resumo['vagas_necessarias']"
            icone="grade"
        />
        <x-indicador rotulo="Motoristas ativos" :valor="$this->resumo['ativos']" icone="motoristas" />
        <x-indicador
            rotulo="Sem definição"
            :valor="$this->resumo['sem_definicao']"
            icone="alerta"
        />
        <x-indicador
            rotulo="Saldo do efetivo"
            :valor="($this->resumo['saldo'] > 0 ? '+' : '').$this->resumo['saldo']"
            icone="usuarios"
            :rodape="$this->resumo['saldo'] < 0 ? 'falta pessoal' : 'sobra para reserva'"
        />
    </div>

    {{-- ------------------------------------------------------------
         Alertas do dimensionamento
         ------------------------------------------------------------ --}}

    @if ($this->alertas)
        <div class="space-y-2">
            @foreach ($this->alertas as $alerta)
                <div class="flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm {{ $alerta->classes() }}">
                    <x-icone
                        :nome="$alerta->severidade === 'erro' ? 'erro' : ($alerta->severidade === 'atencao' ? 'alerta' : 'info')"
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <p>{{ $alerta->mensagem }}</p>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ------------------------------------------------------------
         Ações do mês
         ------------------------------------------------------------ --}}

    <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
        <x-botao wire:click="abrirNovoPosto" variante="secundario" tamanho="pequeno" icone="mais">
            Adicionar ambulância
        </x-botao>

        <x-botao
            wire:click="preencherAutomaticamente"
            wire:confirm="Preencher as vagas abertas com os motoristas disponíveis, em ordem alfabética?"
            variante="secundario"
            tamanho="pequeno"
            icone="raio"
        >
            Preencher vagas
        </x-botao>

        <x-botao href="{{ route('escalas.destinos', $escala) }}" variante="secundario" tamanho="pequeno" icone="usuarios">
            Definir destinos
        </x-botao>

        <div class="ml-auto flex items-center gap-2">
            <span wire:loading class="text-xs text-slate-500">salvando…</span>
            <x-botao wire:click="gerarPlantoes" icone="escalas" tamanho="pequeno">
                Gerar plantões
            </x-botao>
        </div>
    </div>

    {{-- ------------------------------------------------------------
         Formulário de novo posto
         ------------------------------------------------------------ --}}

    @if ($mostrarNovoPosto)
        <x-cartao titulo="Adicionar ambulância à escala">
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label for="nova-ambulancia" class="mb-1.5 block text-sm font-medium text-slate-700">Ambulância</label>
                    <select
                        id="nova-ambulancia"
                        wire:model.live="novaAmbulanciaId"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                    >
                        <option value="">Selecione</option>
                        @foreach ($this->ambulanciasLivres as $ambulancia)
                            <option value="{{ $ambulancia->id }}">
                                {{ $ambulancia->placaFormatada() }}
                                @if ($ambulancia->identificacao) · {{ $ambulancia->identificacao }} @endif
                                @if ($ambulancia->unidade) · {{ $ambulancia->unidade->sigla }} @endif
                            </option>
                        @endforeach
                    </select>
                    @error('novaAmbulanciaId')
                        <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="nova-unidade" class="mb-1.5 block text-sm font-medium text-slate-700">Unidade de lotação</label>
                    <select
                        id="nova-unidade"
                        wire:model="novaUnidadeId"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                    >
                        <option value="">Selecione</option>
                        @foreach ($this->unidades as $unidade)
                            <option value="{{ $unidade->id }}">
                                {{ $unidade->sigla }} — {{ $unidade->regimeNotacao() }} ({{ $unidade->motoristasPorAmbulancia() }} motoristas)
                            </option>
                        @endforeach
                    </select>
                    @error('novaUnidadeId')
                        <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-end gap-2">
                    <x-botao wire:click="adicionarPosto" icone="check">Adicionar</x-botao>
                    <x-botao wire:click="cancelarNovoPosto" variante="secundario">Cancelar</x-botao>
                </div>
            </div>

            @if ($this->ambulanciasLivres->isEmpty())
                <p class="mt-3 text-sm text-slate-500">
                    Todas as ambulâncias ativas já estão nesta escala.
                    <a href="{{ route('ambulancias.create') }}" class="font-medium text-marca-700 hover:underline">Cadastrar outra</a>.
                </p>
            @endif
        </x-cartao>
    @endif

    {{-- ------------------------------------------------------------
         Postos
         ------------------------------------------------------------ --}}

    @forelse ($this->postos as $posto)
        @php
            $regime = $posto->regime();
            $vagas = $posto->vagas();
            $porPosicao = $posto->lotacoes->keyBy('posicao');
            $aberto = $postoAberto === $posto->id;
        @endphp

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200" wire:key="posto-{{ $posto->id }}">

            {{-- Cabeçalho do posto --}}
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-slate-50/70 px-4 py-3">
                <button
                    type="button"
                    wire:click="abrirPosto({{ $posto->id }})"
                    class="flex min-w-0 flex-1 items-center gap-3 text-left"
                >
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-marca-600 text-[0.6rem] font-bold leading-tight text-white">
                        {{ Str::limit($posto->rotuloLotacao(), 7, '') }}
                    </span>

                    <span class="min-w-0">
                        <span class="flex items-center gap-2">
                            <span class="truncate text-sm font-semibold text-slate-900 tabular-nums">
                                {{ $posto->rotuloPlaca() }}
                            </span>
                            <x-badge cor="bg-marca-50 text-marca-800 ring-marca-200">{{ $regime->notacao() }}</x-badge>
                            @if ($posto->completo())
                                <x-badge cor="bg-emerald-100 text-emerald-800 ring-emerald-200">completo</x-badge>
                            @else
                                <x-badge cor="bg-rose-100 text-rose-800 ring-rose-200">
                                    faltam {{ $posto->vagasLivres() }}
                                </x-badge>
                            @endif
                        </span>
                        <span class="block truncate text-xs text-slate-500">
                            {{ $posto->unidade?->nome }} · {{ $posto->vagasOcupadas() }}/{{ $vagas }} motoristas
                        </span>
                    </span>

                    <x-icone nome="seta-baixo" class="size-4 shrink-0 text-slate-400 transition {{ $aberto ? 'rotate-180' : '' }}" />
                </button>

                <div class="flex shrink-0 items-center gap-1.5">
                    <button
                        type="button"
                        wire:click="alternarRotacao({{ $posto->id }})"
                        class="rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset transition {{
                            $posto->continuar_rotacao
                                ? 'bg-sky-50 text-sky-700 ring-sky-200 hover:bg-sky-100'
                                : 'bg-slate-50 text-slate-600 ring-slate-200 hover:bg-slate-100'
                        }}"
                        title="{{ $posto->continuar_rotacao
                            ? 'A fila continua de onde parou no mês anterior. Clique para reiniciar no dia 1º.'
                            : 'A fila reinicia na posição 1 no dia 1º. Clique para continuar do mês anterior.' }}"
                    >
                        {{ $posto->continuar_rotacao ? 'rotação contínua' : 'reinicia no dia 1º' }}
                    </button>

                    <x-botao
                        wire:click="removerPosto({{ $posto->id }})"
                        wire:confirm="Remover {{ $posto->rotuloPlaca() }} desta escala? As lotações e plantões deste posto serão apagados."
                        variante="texto"
                        tamanho="pequeno"
                        icone="lixeira"
                    >
                        <span class="sr-only">Remover posto</span>
                    </x-botao>
                </div>
            </div>

            {{-- Equipe: uma linha por posição do ciclo --}}
            <div class="divide-y divide-slate-100">
                @for ($posicao = 1; $posicao <= $vagas; $posicao++)
                    @php
                        $lotacao = $porPosicao->get($posicao);
                        $motorista = $lotacao?->motorista;
                        // Dia do mês em que esta posição pega o primeiro plantão.
                        $primeiroDia = $posto->inicioVigencia()->copy()->addDays($posicao - 1);
                    @endphp

                    <div class="flex flex-wrap items-center gap-3 px-4 py-2.5" wire:key="pos-{{ $posto->id }}-{{ $posicao }}">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 tabular-nums">
                            {{ $posicao }}
                        </span>

                        <div class="min-w-0 flex-1">
                            @if ($motorista)
                                <p class="truncate text-sm font-medium text-slate-900">{{ $motorista->nome_curto }}</p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $motorista->nome_completo }}
                                    @if ($motorista->cnhVencida())
                                        · <span class="font-medium text-rose-600">CNH vencida</span>
                                    @endif
                                </p>
                            @else
                                <p class="text-sm text-slate-400">Vaga aberta</p>
                            @endif
                        </div>

                        {{-- Seletor de motorista --}}
                        <div class="w-full sm:w-72">
                            <select
                                wire:change="lotar({{ $posto->id }}, {{ $posicao }}, $event.target.value)"
                                aria-label="Motorista da posição {{ $posicao }}"
                                class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                            >
                                <option value="">— vaga aberta —</option>

                                @if ($motorista)
                                    <option value="{{ $motorista->id }}" selected>{{ $motorista->nome_curto }}</option>
                                @endif

                                @foreach ($this->candidatos as $candidato)
                                    <option value="{{ $candidato->id }}">{{ $candidato->nome_curto }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Reordenar --}}
                        <div class="flex shrink-0 items-center gap-0.5">
                            <button
                                type="button"
                                wire:click="mover({{ $posto->id }}, {{ $posicao }}, -1)"
                                @disabled($posicao === 1 || ! $motorista)
                                class="rounded p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent"
                                title="Mover para a posição anterior"
                            >
                                <x-icone nome="seta-baixo" class="size-4 rotate-180" />
                                <span class="sr-only">Subir</span>
                            </button>
                            <button
                                type="button"
                                wire:click="mover({{ $posto->id }}, {{ $posicao }}, 1)"
                                @disabled($posicao === $vagas || ! $motorista)
                                class="rounded p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent"
                                title="Mover para a posição seguinte"
                            >
                                <x-icone nome="seta-baixo" class="size-4" />
                                <span class="sr-only">Descer</span>
                            </button>
                        </div>

                        <span class="w-full shrink-0 text-xs text-slate-400 tabular-nums sm:w-auto">
                            1º plantão: {{ $primeiroDia->format('d/m') }}
                        </span>
                    </div>
                @endfor
            </div>

            {{-- Painel expandido: remanejar a unidade do posto --}}
            @if ($aberto)
                <div class="border-t border-slate-200 bg-slate-50/70 px-4 py-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="unidade-posto-{{ $posto->id }}" class="mb-1.5 block text-xs font-medium text-slate-700">
                                Remanejar para outra unidade
                            </label>
                            <select
                                id="unidade-posto-{{ $posto->id }}"
                                wire:change="alterarUnidadeDoPosto({{ $posto->id }}, $event.target.value)"
                                class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                            >
                                @foreach ($this->unidades as $unidade)
                                    <option value="{{ $unidade->id }}" @selected($unidade->id === $posto->unidade_id)>
                                        {{ $unidade->sigla }} — {{ $unidade->regimeNotacao() }} ({{ $unidade->motoristasPorAmbulancia() }} motoristas)
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">
                                O posto adota o regime da nova unidade, o que pode alterar a quantidade de vagas.
                            </p>
                        </div>

                        <div>
                            <label for="busca-{{ $posto->id }}" class="mb-1.5 block text-xs font-medium text-slate-700">
                                Filtrar motoristas disponíveis
                            </label>
                            <input
                                id="busca-{{ $posto->id }}"
                                type="search"
                                wire:model.live.debounce.300ms="buscaMotorista"
                                placeholder="Digite parte do nome"
                                class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                            >
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $this->candidatos->count() }} motorista(s) disponível(is) para lotação.
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @empty
        <x-cartao>
            <div class="py-8 text-center">
                <span class="mx-auto flex size-11 items-center justify-center rounded-full bg-slate-100">
                    <x-icone nome="ambulancias" class="size-6 text-slate-400" />
                </span>
                <h3 class="mt-3 text-sm font-semibold text-slate-900">Nenhuma ambulância nesta escala</h3>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                    Adicione as ambulâncias que vão operar no mês. Cada uma herda o regime da unidade
                    e abre a quantidade de vagas que o ciclo exige.
                </p>
                <x-botao wire:click="abrirNovoPosto" icone="mais" class="mt-4">Adicionar ambulância</x-botao>
            </div>
        </x-cartao>
    @endforelse
</div>
