@php
    use App\Support\Aniversario;

    $atalhos = [
        Aniversario::HOJE => 'Hoje',
        Aniversario::AMANHA => 'Amanhã',
        Aniversario::SEMANA => 'Próximos 7 dias',
        Aniversario::MES_CORRENTE => 'Este mês',
    ];
@endphp

<x-layouts.app titulo="Aniversariantes" :subtitulo="$descricao">
    <div class="mx-auto max-w-3xl space-y-5">

        {{-- ------------------------------------------------------------
             Filtros: atalhos grandes, depois mês e dia específicos
             ------------------------------------------------------------ --}}

        <div class="space-y-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200 sm:p-4">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($atalhos as $valor => $rotulo)
                    <x-botao
                        href="{{ route('aniversariantes', ['periodo' => $valor]) }}"
                        :variante="$periodo === $valor ? 'suave' : 'secundario'"
                        :aria-current="$periodo === $valor ? 'true' : null"
                    >
                        {{ $rotulo }}
                    </x-botao>
                @endforeach
            </div>

            <div class="grid gap-2 sm:grid-cols-2">
                <form method="GET" action="{{ route('aniversariantes') }}">
                    <label for="mes" class="mb-1 block text-xs font-medium text-slate-600">Ver um mês</label>
                    <select
                        id="mes"
                        name="periodo"
                        onchange="this.form.submit()"
                        class="block h-11 w-full rounded-lg border-0 bg-white px-3 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marca-600"
                    >
                        <option value="">Escolha o mês</option>
                        @foreach (range(1, 12) as $mes)
                            <option value="{{ $mes }}" @selected($periodo === (string) $mes)>
                                {{ ucfirst(\Illuminate\Support\Carbon::create(null, $mes, 1)->translatedFormat('F')) }}
                            </option>
                        @endforeach
                    </select>
                </form>

                <form method="GET" action="{{ route('aniversariantes') }}">
                    <label for="dia" class="mb-1 block text-xs font-medium text-slate-600">Ver um dia</label>
                    <input
                        type="date"
                        id="dia"
                        name="dia"
                        value="{{ $dia?->toDateString() }}"
                        onchange="this.form.submit()"
                        class="block h-11 w-full rounded-lg border-0 bg-white px-3 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marca-600"
                    >
                </form>
            </div>
        </div>

        {{-- ------------------------------------------------------------
             Lista
             ------------------------------------------------------------ --}}

        <x-cartao
            :titulo="$aniversariantes->count().' '.($aniversariantes->count() === 1 ? 'aniversariante' : 'aniversariantes')"
            :descricao="$descricao"
        >
            @forelse ($aniversariantes as $item)
                @php
                    $motorista = $item['motorista'];
                    $data = $item['data'];
                    $lotacao = $lotacoes->get($motorista->id);
                    $ehHoje = $data->isToday();
                @endphp

                <div class="border-b border-slate-100 py-3 first:pt-0 last:border-0 last:pb-0">
                    <div class="flex items-start gap-3">
                        <span class="flex size-11 shrink-0 flex-col items-center justify-center rounded-lg {{ $ehHoje ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700' }}">
                            <span class="text-sm font-bold leading-none tabular-nums">{{ $data->format('d') }}</span>
                            <span class="text-[0.6rem] font-medium uppercase">{{ mb_substr($data->translatedFormat('M'), 0, 3) }}</span>
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $motorista->nome_curto }}
                                @if ($ehHoje) <span aria-hidden="true">🎂</span> @endif
                            </p>
                            <p class="text-xs text-slate-500">{{ $motorista->nome_completo }}</p>
                            <p class="mt-0.5 text-xs text-slate-600">
                                {{ $ehHoje ? 'Completa' : ($data->isPast() ? 'Completou' : 'Completa') }}
                                <strong>{{ $item['idade'] }} anos</strong>
                                {{ $ehHoje ? 'hoje' : 'em '.$data->format('d/m').' ('.$data->translatedFormat('l').')' }}
                                @if ($lotacao?->posto)
                                    · {{ $lotacao->posto->rotuloLotacao() }}
                                @elseif ($lotacao?->tipo_destino)
                                    · {{ $lotacao->tipo_destino->rotulo() }}
                                @endif
                            </p>
                        </div>
                    </div>

                    @if ($motorista->telefone_1)
                        <div class="mt-2.5 grid grid-cols-2 gap-2 sm:ml-14 sm:flex">
                            @if ($item['parabens'])
                                <x-botao :href="$item['parabens']" target="_blank" rel="noopener" variante="suave" icone="whatsapp">
                                    Parabéns
                                </x-botao>
                            @endif
                            <x-botao
                                href="tel:{{ \App\Support\Telefone::digitos($motorista->telefone_1) }}"
                                variante="secundario"
                                icone="telefone"
                            >
                                Ligar
                            </x-botao>
                        </div>
                    @endif
                </div>
            @empty
                <div class="py-8 text-center">
                    <span class="mx-auto flex size-11 items-center justify-center rounded-full bg-slate-100">
                        <x-icone nome="bolo" class="size-6 text-slate-400" />
                    </span>
                    <p class="mt-3 text-sm text-slate-600">Nenhum aniversariante neste período.</p>
                    @if ($periodo === Aniversario::HOJE)
                        <a href="{{ route('aniversariantes', ['periodo' => Aniversario::SEMANA]) }}" class="mt-1 inline-block text-sm font-medium text-marca-700 hover:underline">
                            Ver os dos próximos 7 dias
                        </a>
                    @endif
                </div>
            @endforelse
        </x-cartao>
    </div>
</x-layouts.app>
