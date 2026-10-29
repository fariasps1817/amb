@php
    $ehHoje = $dia->isToday();
    $anterior = $dia->copy()->subDay()->toDateString();
    $seguinte = $dia->copy()->addDay()->toDateString();
    $comSobreavisoParam = $comSobreaviso ? ['sobreaviso' => 1] : [];
@endphp

<x-layouts.app
    titulo="Plantão do dia"
    :subtitulo="ucfirst($dia->translatedFormat('l, d \d\e F \d\e Y'))"
>
    <div class="mx-auto max-w-3xl space-y-5">

        {{-- ------------------------------------------------------------
             Escolha do dia: setas, atalhos e calendário
             ------------------------------------------------------------ --}}

        <div class="flex items-center gap-2 rounded-xl bg-white p-2 shadow-sm ring-1 ring-slate-200">
            <a
                href="{{ route('plantao', ['dia' => $anterior] + $comSobreavisoParam) }}"
                class="flex size-11 shrink-0 items-center justify-center rounded-lg text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                title="Dia anterior"
            >
                <x-icone nome="seta-esquerda" class="size-5" />
                <span class="sr-only">Dia anterior</span>
            </a>

            <form method="GET" action="{{ route('plantao') }}" class="min-w-0 flex-1">
                @if ($comSobreaviso)
                    <input type="hidden" name="sobreaviso" value="1">
                @endif
                <label for="dia" class="sr-only">Escolher o dia</label>
                <input
                    type="date"
                    id="dia"
                    name="dia"
                    value="{{ $dia->toDateString() }}"
                    onchange="this.form.submit()"
                    class="block h-11 w-full rounded-lg border-0 bg-white px-3 text-center text-sm font-medium text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-marca-600"
                >
            </form>

            <a
                href="{{ route('plantao', ['dia' => $seguinte] + $comSobreavisoParam) }}"
                class="flex size-11 shrink-0 items-center justify-center rounded-lg text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                title="Dia seguinte"
            >
                <x-icone nome="seta-direita" class="size-5" />
                <span class="sr-only">Dia seguinte</span>
            </a>
        </div>

        <div class="flex gap-2">
            <x-botao
                href="{{ route('plantao', $comSobreavisoParam) }}"
                :variante="$ehHoje ? 'suave' : 'secundario'"
                class="flex-1"
            >
                Hoje
            </x-botao>
            <x-botao
                href="{{ route('plantao', ['dia' => today()->addDay()->toDateString()] + $comSobreavisoParam) }}"
                :variante="$dia->isTomorrow() ? 'suave' : 'secundario'"
                class="flex-1"
            >
                Amanhã
            </x-botao>
        </div>

        {{-- ------------------------------------------------------------
             Avisos sobre a escala do mês
             ------------------------------------------------------------ --}}

        @if ($escala === null)
            <div class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
                <x-icone nome="alerta" class="mt-0.5 size-4 shrink-0" />
                <p>Ainda não existe escala para {{ $dia->translatedFormat('F \d\e Y') }}.</p>
            </div>
        @elseif ($escala->ehRascunho())
            <div class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
                <x-icone nome="alerta" class="mt-0.5 size-4 shrink-0" />
                <p>
                    A escala de {{ $dia->translatedFormat('F') }} ainda é um <strong>rascunho</strong>: estes plantões podem mudar até ela ser publicada.
                </p>
            </div>
        @endif

        @foreach ($semPlantonista as $posto)
            <div class="flex items-start gap-2.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800">
                <x-icone nome="alerta" class="mt-0.5 size-4 shrink-0" />
                <p><strong>{{ $posto->rotuloLotacao() }}</strong> ({{ $posto->rotuloPlaca() }}) está sem motorista neste dia.</p>
            </div>
        @endforeach

        {{-- ------------------------------------------------------------
             Quem está de plantão
             ------------------------------------------------------------ --}}

        <x-cartao
            :titulo="$plantoes->count().' '.($plantoes->count() === 1 ? 'ambulância' : 'ambulâncias').' de plantão'"
            :descricao="$plantoes->isNotEmpty() ? 'Das '.$plantoes->first()->horaEntradaTexto().' de '.$dia->format('d/m').' às '.$plantoes->first()->horaSaidaTexto().' de '.$dia->copy()->addDay()->format('d/m') : null"
        >
            @forelse ($plantoes as $plantao)
                <div class="border-b border-slate-100 last:border-0">
                    <x-plantonista :plantao="$plantao" />
                </div>
            @empty
                <p class="py-6 text-center text-sm text-slate-500">Nenhum plantão registrado para este dia.</p>
            @endforelse
        </x-cartao>

        {{-- ------------------------------------------------------------
             Mensagem para as unidades
             ------------------------------------------------------------ --}}

        @if ($plantoes->isNotEmpty())
            <x-cartao
                titulo="Mensagem para as unidades"
                descricao="Confira o texto, mude o que quiser e envie. No WhatsApp, escolha o grupo ou a pessoa."
            >
                @if ($sobreaviso->isNotEmpty())
                    <form method="GET" action="{{ route('plantao') }}" class="mb-3">
                        <input type="hidden" name="dia" value="{{ $dia->toDateString() }}">
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                name="sobreaviso"
                                value="1"
                                @checked($comSobreaviso)
                                onchange="this.form.submit()"
                                class="size-5 rounded border-slate-300 text-marca-600 focus:ring-marca-600"
                            >
                            Incluir quem está de sobreaviso ({{ $sobreaviso->count() }})
                        </label>
                    </form>
                @endif

                <div
                    x-data="{
                        texto: @js($texto),
                        copiado: false,
                        podeCompartilhar: typeof navigator.share === 'function',
                    }"
                >
                    <label for="texto-plantao" class="sr-only">Texto da mensagem</label>
                    <textarea
                        id="texto-plantao"
                        x-model="texto"
                        rows="14"
                        class="block w-full rounded-lg border-0 bg-slate-50 px-3 py-2.5 font-mono text-[0.8rem] leading-relaxed text-slate-800 ring-1 ring-inset ring-slate-200 focus:bg-white focus:ring-2 focus:ring-marca-600"
                    ></textarea>

                    <div class="mt-3 grid gap-2 sm:flex sm:flex-wrap">
                        {{-- Link montado na hora do toque, com o texto já editado. --}}
                        <a
                            :href="'https://wa.me/?text=' + encodeURIComponent(texto)"
                            href="https://wa.me/?text={{ rawurlencode($texto) }}"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-marca-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-marca-700"
                        >
                            <x-icone nome="whatsapp" class="size-4 shrink-0" />
                            Enviar pelo WhatsApp
                        </a>

                        <x-botao
                            x-on:click="copiarTexto(texto).then(ok => { copiado = ok; setTimeout(() => copiado = false, 2000) })"
                            variante="secundario"
                            tamanho="grande"
                            icone="copiar"
                        >
                            <span x-text="copiado ? 'Copiado!' : 'Copiar texto'">Copiar texto</span>
                        </x-botao>

                        {{-- No celular abre a lista de apps do aparelho, que
                             permite mandar para mais de um grupo de uma vez. --}}
                        <x-botao
                            x-show="podeCompartilhar"
                            x-cloak
                            x-on:click="navigator.share({ text: texto }).catch(() => {})"
                            variante="secundario"
                            tamanho="grande"
                            icone="compartilhar"
                        >
                            Compartilhar…
                        </x-botao>
                    </div>
                </div>
            </x-cartao>
        @endif
    </div>
</x-layouts.app>
