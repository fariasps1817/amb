<div
    class="space-y-5"
    x-data
    @aviso.window="$dispatch('mostrar-aviso', $event.detail)"
>
    {{-- ------------------------------------------------------------
         Resumo do dimensionamento
         ------------------------------------------------------------ --}}

    {{-- No celular ficam os tres numeros que decidem a montagem; os outros
         empurravam a primeira ambulancia para fora da tela. --}}
    <div class="grid grid-cols-3 gap-2 sm:gap-3 lg:grid-cols-5">
        <x-indicador rotulo="Ambulâncias" :valor="$this->resumo['postos']" icone="ambulancias" />
        <x-indicador
            rotulo="Vagas preenchidas"
            :valor="$this->resumo['vagas_ocupadas'].'/'.$this->resumo['vagas_necessarias']"
            icone="grade"
        />
        <x-indicador rotulo="Motoristas ativos" :valor="$this->resumo['ativos']" icone="motoristas" class="hidden sm:block" />
        <x-indicador
            rotulo="Sem definição"
            :valor="$this->resumo['sem_definicao']"
            icone="alerta"
        />
        <x-indicador
            class="hidden sm:block"
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

        @if ($this->postos->count() > 1)
            <x-botao x-on:click="$dispatch('abrir-ordenacao')" variante="secundario" tamanho="pequeno" icone="ordenar">
                Ordenar ambulâncias
            </x-botao>
        @endif

        <div class="ml-auto flex items-center gap-2">
            <span wire:loading class="text-xs text-slate-500">salvando…</span>
            <x-botao
                wire:click="gerarPlantoes"
                wire:confirm="Refaz todos os plantões do mês com a equipe montada acima. As trocas combinadas manualmente são preservadas.&#10;&#10;A rotação é recalculada a partir do mês anterior: se ele mudou, os dias podem mudar aqui.&#10;&#10;Continuar?"
                icone="escalas"
                tamanho="pequeno"
            >
                Gerar plantões
            </x-botao>
        </div>
    </div>

    <livewire:ordenar-postos :escala="$escala" wire:key="ordenar-postos" />

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

            {{-- Cabeçalho do posto: placa e unidade na primeira linha, situação
                 e rotação na segunda. Numa linha só, no celular a placa era
                 espremida até sumir e os selos ficavam por baixo dos botões. --}}
            <div class="border-b border-slate-200 bg-slate-50/70 px-4 py-3">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="abrirPosto({{ $posto->id }})"
                        class="flex min-w-0 flex-1 items-center gap-3 text-left"
                        aria-expanded="{{ $aberto ? 'true' : 'false' }}"
                    >
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-marca-600 px-0.5 text-center text-[0.6rem] font-bold leading-tight text-white">
                            {{ Str::limit($posto->rotuloLotacao(), 9, '') }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-slate-900 tabular-nums">
                                {{ $posto->rotuloPlaca() }}
                            </span>
                            <span class="block truncate text-xs text-slate-500">
                                {{ $posto->unidade?->nome }} · {{ $posto->vagasOcupadas() }}/{{ $vagas }} motoristas
                                @if ($posto->data_inicio || $posto->data_fim)
                                    · opera de {{ $posto->inicioVigencia()->format('d/m') }}
                                    a {{ $posto->fimVigencia()->format('d/m') }}
                                @endif
                            </span>
                        </span>

                        <x-icone nome="seta-baixo" class="size-4 shrink-0 text-slate-400 transition {{ $aberto ? 'rotate-180' : '' }}" />
                    </button>

                    <x-botao
                        wire:click="removerPosto({{ $posto->id }})"
                        wire:confirm="Remover {{ $posto->rotuloPlaca() }} desta escala? As lotações e plantões deste posto serão apagados."
                        variante="texto"
                        icone="lixeira"
                        class="size-10 shrink-0 !p-0"
                        title="Remover esta ambulância da escala"
                    >
                        <span class="sr-only">Remover posto</span>
                    </x-botao>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2 sm:pl-[3.25rem]">
                    <x-badge cor="bg-marca-50 text-marca-800 ring-marca-200">{{ $regime->notacao() }}</x-badge>
                    @if ($posto->completo())
                        <x-badge cor="bg-emerald-100 text-emerald-800 ring-emerald-200">completo</x-badge>
                    @else
                        <x-badge cor="bg-rose-100 text-rose-800 ring-rose-200">
                            faltam {{ $posto->vagasLivres() }}
                        </x-badge>
                    @endif

                    {{-- Muda o mes inteiro desta ambulancia: pede confirmacao. --}}
                    <button
                        type="button"
                        wire:click="alternarRotacao({{ $posto->id }})"
                        wire:confirm="{{ $posto->continuar_rotacao
                            ? 'Reiniciar a fila desta ambulância na posição 1 no dia 1º? Os dias de plantão de todos mudam quando os plantões forem gerados de novo.'
                            : 'Voltar a continuar a fila de onde parou no mês anterior? Os dias de plantão de todos mudam quando os plantões forem gerados de novo.' }}"
                        class="ml-auto min-h-9 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 ring-inset transition {{
                            $posto->continuar_rotacao
                                ? 'bg-sky-50 text-sky-700 ring-sky-200 hover:bg-sky-100'
                                : 'bg-slate-50 text-slate-600 ring-slate-200 hover:bg-slate-100'
                        }}"
                        title="{{ $posto->continuar_rotacao
                            ? 'A fila continua de onde parou no mês anterior. Toque para reiniciar no dia 1º.'
                            : 'A fila reinicia na posição 1 no dia 1º. Toque para continuar do mês anterior.' }}"
                    >
                        {{ $posto->continuar_rotacao ? 'Rotação contínua' : 'Reinicia no dia 1º' }}
                    </button>
                </div>
            </div>

            {{-- Painel expandido: logo abaixo do cabeçalho tocado. Embaixo da equipe ele abria fora da tela no celular, e o toque parecia não fazer nada. --}}
            @if ($aberto)
                <div class="border-b border-slate-200 bg-slate-50/70 px-4 py-3">
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

                        {{-- Período de operação: para a ambulância que entra ou
                             sai no meio do mês --}}
                        <div class="sm:col-span-2">
                            <p class="mb-1.5 text-xs font-medium text-slate-700">
                                Período de operação no mês
                            </p>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="inicio-posto-{{ $posto->id }}" class="mb-1 block text-xs text-slate-500">
                                        Primeiro dia de plantão
                                    </label>
                                    <input
                                        id="inicio-posto-{{ $posto->id }}"
                                        type="date"
                                        value="{{ $posto->data_inicio?->toDateString() }}"
                                        min="{{ $escala->primeiroDia()->toDateString() }}"
                                        max="{{ $escala->ultimoDia()->toDateString() }}"
                                        wire:change="alterarVigencia({{ $posto->id }}, 'data_inicio', $event.target.value)"
                                        class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                                    >
                                </div>

                                <div>
                                    <label for="fim-posto-{{ $posto->id }}" class="mb-1 block text-xs text-slate-500">
                                        Último dia de plantão
                                    </label>
                                    <input
                                        id="fim-posto-{{ $posto->id }}"
                                        type="date"
                                        value="{{ $posto->data_fim?->toDateString() }}"
                                        min="{{ $escala->primeiroDia()->toDateString() }}"
                                        max="{{ $escala->ultimoDia()->toDateString() }}"
                                        wire:change="alterarVigencia({{ $posto->id }}, 'data_fim', $event.target.value)"
                                        class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                                    >
                                </div>
                            </div>

                            <p class="mt-1 text-xs text-slate-500">
                                Deixe em branco para operar o mês inteiro. Use quando a ambulância entra ou sai de
                                operação no meio do mês — por exemplo, escala de {{ $posto->regimeNotacao() }} a partir
                                do dia {{ $escala->primeiroDia()->copy()->addDays(3)->format('d/m') }}.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Equipe: uma linha por posição do ciclo --}}
            <div class="divide-y divide-slate-100">
                @foreach ($this->ordensDeEntrada[$posto->id] ?? range(1, $vagas) as $indice => $posicao)
                    @php
                        $lotacao = $porPosicao->get($posicao);
                        $motorista = $lotacao?->motorista;

                        // A equipe é listada na ordem em que entra no mês, e não
                        // pela posição do ciclo: com a rotação contínua quem abre
                        // o mês pode ser a posição 4. Por isso o dia do primeiro
                        // plantão sai do índice desta ordem, e não da posição.
                        $primeiroDia = $posto->inicioVigencia()->copy()->addDays($indice);

                        // As setas trocam com o vizinho DA LISTA. Como a ordem é
                        // a fila girada, o vizinho de cima da posição 1 é a
                        // última posição — daí a direção sair da subtração.
                        $ordem = $this->ordensDeEntrada[$posto->id] ?? range(1, $vagas);
                        $acima = $ordem[$indice - 1] ?? null;
                        $abaixo = $ordem[$indice + 1] ?? null;
                    @endphp

                    {{-- No celular: número, seletor e setas numa linha só, e o dia do
                         primeiro plantão embaixo. O nome some ali porque o próprio
                         seletor já mostra quem está na posição. --}}
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5" wire:key="pos-{{ $posto->id }}-{{ $posicao }}">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 tabular-nums">
                            {{ $indice + 1 }}
                        </span>

                        <div class="hidden min-w-0 flex-1 sm:block">
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
                        <div class="min-w-0 flex-1 sm:w-72 sm:flex-none">
                            <select
                                wire:change="lotar({{ $posto->id }}, {{ $posicao }}, $event.target.value)"
                                aria-label="Motorista da posição {{ $posicao }}"
                                class="block w-full rounded-lg border-0 bg-white px-2.5 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600 sm:py-1.5"
                            >
                                <option value="">— vaga aberta —</option>

                                @if ($motorista)
                                    <option value="{{ $motorista->id }}" selected>{{ $motorista->nome_curto }}</option>
                                @endif

                                <optgroup label="Sem escala neste mês">
                                    @foreach ($this->candidatos as $candidato)
                                        <option value="{{ $candidato->id }}">{{ $candidato->nome_curto }}</option>
                                    @endforeach
                                </optgroup>

                                {{--
                                    Quem já está em outra ambulância também é
                                    oferecido: remanejar no meio da montagem é
                                    rotina — férias, licença, permuta. Escolher
                                    move a pessoa para cá e deixa a posição de
                                    origem aberta.
                                --}}
                                @php
                                    $deOutrosPostos = $this->escaladosEmOutrosPostos
                                        ->where('escala_posto_id', '!=', $posto->id);
                                @endphp

                                @if ($deOutrosPostos->isNotEmpty())
                                    <optgroup label="Escalados em outra ambulância — traz para cá">
                                        @foreach ($deOutrosPostos as $outra)
                                            <option value="{{ $outra->motorista_id }}">
                                                {{ $outra->motorista->nome_curto }} · {{ $outra->posto->rotuloLotacao() }} {{ $outra->posto->rotuloPlaca() }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                        </div>

                        {{-- Reordenar --}}
                        <div class="flex shrink-0 items-center gap-0.5">
                            <button
                                type="button"
                                wire:click="mover({{ $posto->id }}, {{ $posicao }}, {{ ($acima ?? $posicao) - $posicao }})"
                                @disabled($acima === null || ! $motorista)
                                class="flex size-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent sm:size-8"
                                title="Mover para a posição anterior"
                            >
                                <x-icone nome="seta-baixo" class="size-4 rotate-180" />
                                <span class="sr-only">Subir</span>
                            </button>
                            <button
                                type="button"
                                wire:click="mover({{ $posto->id }}, {{ $posicao }}, {{ ($abaixo ?? $posicao) - $posicao }})"
                                @disabled($abaixo === null || ! $motorista)
                                class="flex size-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30 disabled:hover:bg-transparent sm:size-8"
                                title="Mover para a posição seguinte"
                            >
                                <x-icone nome="seta-baixo" class="size-4" />
                                <span class="sr-only">Descer</span>
                            </button>
                        </div>

                        <span class="w-full shrink-0 pl-10 text-xs text-slate-400 tabular-nums sm:w-auto sm:pl-0">
                            1º plantão: {{ $primeiroDia->format('d/m') }}
                            @if ($motorista?->cnhVencida())
                                <span class="font-medium text-rose-600 sm:hidden">· CNH vencida</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
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
