@php
    $filtros = [
        'pendentes' => 'Pendentes',
        'escalados' => 'Escalados',
        'destino' => 'Com destino',
        'todos' => 'Todos',
    ];
@endphp

<div class="space-y-5">

    {{-- ------------------------------------------------------------
         Resumo do fechamento
         ------------------------------------------------------------ --}}

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-indicador rotulo="Motoristas ativos" :valor="$this->resumo['ativos']" icone="motoristas" />
        <x-indicador rotulo="Escalados" :valor="$this->resumo['vagas_ocupadas']" icone="grade" />
        <x-indicador rotulo="Reserva / apoio" :valor="$this->resumo['disponiveis']" icone="usuarios" />
        <x-indicador
            rotulo="Sem definição"
            :valor="$this->resumo['sem_definicao']"
            icone="alerta"
            :rodape="$this->resumo['sem_definicao'] === 0 ? 'mês fechado' : 'precisa definir'"
        />
    </div>

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
         Filtros e ações
         ------------------------------------------------------------ --}}

    <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1">
                @foreach ($filtros as $chave => $rotulo)
                    <button
                        type="button"
                        wire:click="filtrar('{{ $chave }}')"
                        class="rounded-md px-2.5 py-1 text-xs font-medium transition {{
                            $filtro === $chave
                                ? 'bg-white text-slate-900 shadow-sm'
                                : 'text-slate-600 hover:text-slate-900'
                        }}"
                    >
                        {{ $rotulo }}
                        <span class="ml-0.5 tabular-nums opacity-60">{{ $this->contagens[$chave] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="min-w-48 flex-1">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="busca"
                    placeholder="Buscar motorista"
                    aria-label="Buscar motorista"
                    class="block w-full rounded-lg border-0 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                >
            </div>

            @if ($this->contagens['pendentes'] > 0)
                <x-botao
                    wire:click="todosParaReserva"
                    wire:confirm="Definir os {{ $this->contagens['pendentes'] }} motorista(s) pendente(s) como reserva/sobreaviso?"
                    variante="suave"
                    tamanho="pequeno"
                    icone="raio"
                >
                    Pendentes → reserva
                </x-botao>
            @endif

            <x-botao href="{{ route('escalas.show', $escala) }}" variante="secundario" tamanho="pequeno" icone="olho">
                Ver planilha
            </x-botao>
        </div>
    </div>

    {{-- ------------------------------------------------------------
         Lista de motoristas
         ------------------------------------------------------------ --}}

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <ul class="divide-y divide-slate-100">
            @forelse ($this->lotacoes as $lotacao)
                @php
                    $motorista = $lotacao->motorista;
                    $editando = $emEdicao === $lotacao->id;
                @endphp

                <li class="p-4" wire:key="lotacao-{{ $lotacao->id }}">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('motoristas.show', $motorista) }}" class="font-medium text-slate-900 hover:text-marca-700">
                                    {{ $motorista->nome_curto }}
                                </a>

                                @if ($lotacao->escalado())
                                    <x-badge cor="bg-marca-50 text-marca-800 ring-marca-200">
                                        {{ $lotacao->posto?->rotuloLotacao() }} · pos. {{ $lotacao->posicao }}
                                    </x-badge>
                                @elseif ($lotacao->tipo_destino)
                                    <x-badge :cor="$lotacao->tipo_destino->corBadge()">
                                        {{ $lotacao->tipo_destino->rotulo() }}
                                    </x-badge>
                                @else
                                    <x-badge cor="bg-rose-100 text-rose-800 ring-rose-200">sem definição</x-badge>
                                @endif

                                @if ($motorista->cnhVencida())
                                    <x-badge cor="bg-rose-100 text-rose-800 ring-rose-200">CNH vencida</x-badge>
                                @endif
                                @if ($motorista->contratoEncerrado())
                                    <x-badge cor="bg-rose-100 text-rose-800 ring-rose-200">contrato encerrado</x-badge>
                                @endif
                            </div>

                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ $motorista->nome_completo }} ·
                                {{ $motorista->vinculo->rotulo() }}
                                @if ($lotacao->plantoes_previstos > 0)
                                    · {{ $lotacao->plantoes_previstos }} plantão(ões)
                                @endif
                            </p>

                            @if ($lotacao->textoOcorrencia())
                                <p class="mt-1 text-xs italic text-slate-600">{{ $lotacao->textoOcorrencia() }}</p>
                            @endif
                        </div>

                        {{-- Seletor de destino: bloqueado para quem já está em uma
                             ambulância, para evitar tirar alguém da escala por engano --}}
                        <div class="flex w-full shrink-0 items-center gap-2 sm:w-auto">
                            @if ($lotacao->escalado())
                                <span class="text-xs text-slate-500">
                                    Escalado em {{ $lotacao->posto?->rotuloPlaca() }}.
                                    <a href="{{ route('escalas.montar', $escala) }}" class="font-medium text-marca-700 hover:underline">
                                        Alterar na montagem
                                    </a>
                                </span>
                            @else
                                <select
                                    wire:change="definir({{ $motorista->id }}, $event.target.value)"
                                    aria-label="Destino de {{ $motorista->nome_curto }}"
                                    class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset sm:w-56 focus:ring-2 focus:ring-inset focus:ring-marca-600 {{
                                        $lotacao->tipo_destino ? 'ring-slate-300' : 'ring-rose-300'
                                    }}"
                                >
                                    <option value="">— definir destino —</option>
                                    @foreach (\App\Enums\TipoDestino::opcoes() as $valor => $rotulo)
                                        <option value="{{ $valor }}" @selected($lotacao->tipo_destino?->value === $valor)>
                                            {{ $rotulo }}
                                        </option>
                                    @endforeach
                                </select>

                                <x-botao
                                    wire:click="editar({{ $lotacao->id }})"
                                    variante="texto"
                                    tamanho="pequeno"
                                    :icone="$editando ? 'fechar' : 'lapis'"
                                >
                                    <span class="sr-only">Detalhes</span>
                                </x-botao>
                            @endif
                        </div>
                    </div>

                    {{-- Painel de detalhes: período, observação e plantões previstos --}}
                    @if ($editando)
                        <div class="mt-3 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
                            <div class="grid gap-3 sm:grid-cols-4">
                                <div>
                                    <label for="inicio-{{ $lotacao->id }}" class="mb-1 block text-xs font-medium text-slate-700">
                                        Início do período
                                    </label>
                                    <input
                                        id="inicio-{{ $lotacao->id }}"
                                        type="date"
                                        wire:model="periodoInicio"
                                        class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                                    >
                                    @error('periodoInicio') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="fim-{{ $lotacao->id }}" class="mb-1 block text-xs font-medium text-slate-700">
                                        Fim do período
                                    </label>
                                    <input
                                        id="fim-{{ $lotacao->id }}"
                                        type="date"
                                        wire:model="periodoFim"
                                        class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                                    >
                                    @error('periodoFim') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label for="plantoes-{{ $lotacao->id }}" class="mb-1 block text-xs font-medium text-slate-700">
                                        Plantões previstos
                                    </label>
                                    <input
                                        id="plantoes-{{ $lotacao->id }}"
                                        type="number"
                                        min="0"
                                        max="31"
                                        wire:model="plantoesPrevistos"
                                        class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 tabular-nums focus:ring-2 focus:ring-inset focus:ring-marca-600"
                                    >
                                    @error('plantoesPrevistos') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>

                                @if ($lotacao->tipo_destino === \App\Enums\TipoDestino::Apoio)
                                    <div>
                                        <label for="apoio-{{ $lotacao->id }}" class="mb-1 block text-xs font-medium text-slate-700">
                                            Unidade de apoio
                                        </label>
                                        <select
                                            id="apoio-{{ $lotacao->id }}"
                                            wire:model="unidadeApoioId"
                                            class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                                        >
                                            <option value="">Nenhuma</option>
                                            @foreach ($this->unidades as $unidade)
                                                <option value="{{ $unidade->id }}">{{ $unidade->sigla }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif

                                <div class="sm:col-span-4">
                                    <label for="obs-{{ $lotacao->id }}" class="mb-1 block text-xs font-medium text-slate-700">
                                        Observação impressa na lista mensal
                                    </label>
                                    <input
                                        id="obs-{{ $lotacao->id }}"
                                        type="text"
                                        maxlength="255"
                                        wire:model="observacao"
                                        placeholder="Ex.: Férias de 01 a 30/08/26 · Início em 01/08/2026"
                                        class="block w-full rounded-lg border-0 bg-white px-2.5 py-1.5 text-sm shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-marca-600"
                                    >
                                    <p class="mt-1 text-xs text-slate-500">
                                        Se deixar em branco e informar o período acima, o texto é montado automaticamente.
                                    </p>
                                    @error('observacao') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="mt-3 flex justify-end gap-2">
                                <x-botao wire:click="fecharEdicao" variante="secundario" tamanho="pequeno">Cancelar</x-botao>
                                <x-botao wire:click="salvarDetalhes" tamanho="pequeno" icone="check">Salvar</x-botao>
                            </div>
                        </div>
                    @endif
                </li>
            @empty
                <li class="p-10 text-center">
                    @if ($filtro === 'pendentes')
                        <x-icone nome="check" class="mx-auto size-8 text-emerald-500" />
                        <h3 class="mt-2 text-sm font-semibold text-slate-900">Todos os motoristas têm destino</h3>
                        <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                            O efetivo do mês está inteiramente definido. A lista mensal de ocorrências vai sair completa.
                        </p>
                        <x-botao href="{{ route('escalas.show', $escala) }}" class="mt-4" icone="olho">
                            Ver planilha da escala
                        </x-botao>
                    @else
                        <p class="text-sm text-slate-500">Nenhum motorista neste filtro.</p>
                    @endif
                </li>
            @endforelse
        </ul>
    </div>
</div>
