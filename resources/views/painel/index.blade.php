<x-layouts.app titulo="Painel" :subtitulo="ucfirst($hoje->translatedFormat('l, d \d\e F \d\e Y'))">

    {{--
        Pensado para o celular, na ordem do dia a dia: primeiro quem está de
        plantão (e o envio para as unidades), depois aniversariantes e a
        situação da escala. Números de cadastro ficam nas telas de cadastro.
    --}}

    {{-- grid-cols-1 e o minmax(0, 1fr): sem ele a coluna cresce ate caber a
         linha mais larga e a pagina rola para o lado no celular. --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------------
             Solicitações de ambulância: acima de tudo, porque tem alguém
             esperando. O número vermelho se atualiza sozinho (app.js), e a
             página recarrega quando ele muda, para o texto acompanhar.
             ------------------------------------------------------------ --}}

        @if ($solicitacoesAguardando !== null)
            @php $aguardando = $solicitacoesAguardando['total']; @endphp
            <a
                href="{{ route('solicitacoes.index') }}"
                data-recarregar-ao-mudar-contagem
                class="group relative flex items-center gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 transition sm:px-5 lg:col-span-3 {{ $aguardando > 0 ? 'ring-rose-300 hover:ring-rose-400' : 'ring-slate-200 hover:ring-marca-300' }}"
            >
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full {{ $aguardando > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-500' }}">
                    <x-icone nome="sino" class="size-6" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold text-slate-900">Solicitações de ambulância</span>
                    <span class="block text-xs {{ $aguardando > 0 ? 'font-medium text-rose-700' : 'text-slate-500' }}">
                        @if ($aguardando > 0)
                            {{ $aguardando }} aguardando atendimento ·
                            {{ $aguardando === 1 ? 'há' : 'a mais antiga há' }} {{ $solicitacoesAguardando['mais_antiga']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}
                        @else
                            Nenhuma aguardando atendimento
                        @endif
                    </span>
                </span>

                <x-icone nome="seta-direita" class="size-4 shrink-0 text-slate-400 transition group-hover:translate-x-0.5" />

                {{-- O número vermelho, no cantinho do cartão --}}
                <span
                    data-contador-solicitacoes
                    @if ($aguardando === 0) hidden @endif
                    class="absolute -right-1.5 -top-1.5 flex h-6 min-w-6 items-center justify-center rounded-full bg-rose-600 px-1.5 text-xs font-bold text-white tabular-nums ring-2 ring-white"
                >{{ $aguardando }}</span>
            </a>
        @endif

        {{-- ------------------------------------------------------------
             De plantão hoje
             ------------------------------------------------------------ --}}

        <div class="space-y-3 lg:col-span-2">
            @if ($antesDaTroca)
                <div class="flex items-start gap-2.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5 text-sm text-sky-800">
                    <x-icone nome="relogio" class="mt-0.5 size-4 shrink-0" />
                    <p>
                        Até as {{ $plantoes->first()->horaEntradaTexto() }}, quem está nas ambulâncias ainda é o plantão de ontem.
                        <a href="{{ route('plantao', ['dia' => $hoje->copy()->subDay()->toDateString()]) }}" class="font-semibold underline">Ver o plantão de ontem</a>
                    </p>
                </div>
            @endif

            <x-cartao
                titulo="De plantão hoje"
                :descricao="$plantoes->isNotEmpty()
                    ? $hoje->format('d/m').' · das '.$plantoes->first()->horaEntradaTexto().' às '.$plantoes->first()->horaSaidaTexto().' do dia seguinte'
                    : $hoje->format('d/m/Y')"
            >
                @if ($propostasDeHoje->isNotEmpty())
                    <x-aviso-propostas :propostas="$propostasDeHoje" />
                @elseif ($plantoes->isEmpty())
                    <p class="py-6 text-center text-sm text-slate-500">Nenhum plantão registrado para hoje.</p>
                @else
                    <div class="-mt-2.5 divide-y divide-slate-100">
                        @foreach ($plantoes as $plantao)
                            <x-plantonista :plantao="$plantao" />
                        @endforeach
                    </div>

                    @foreach ($semPlantonista as $posto)
                        <div class="mt-2 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                            <x-icone nome="alerta" class="mt-px size-4 shrink-0" />
                            <p><strong>{{ $posto->rotuloLotacao() }}</strong> ({{ $posto->rotuloPlaca() }}) está sem motorista hoje.</p>
                        </div>
                    @endforeach

                    {{-- Envio para as unidades: um toque abre o WhatsApp com a
                         lista pronta; é só escolher o grupo. --}}
                    <div
                        class="mt-4 grid gap-2 border-t border-slate-100 pt-4 sm:flex sm:flex-wrap sm:items-center"
                        x-data="{ copiado: false, texto: @js($textoDoPlantao) }"
                    >
                        <x-botao :href="$linkDoPlantao" target="_blank" rel="noopener" tamanho="grande" icone="whatsapp">
                            Enviar para as unidades pelo WhatsApp
                        </x-botao>
                        <x-botao
                            x-on:click="copiarTexto(texto).then(ok => { copiado = ok; setTimeout(() => copiado = false, 2000) })"
                            variante="secundario"
                            tamanho="grande"
                            icone="copiar"
                        >
                            <span x-text="copiado ? 'Copiado!' : 'Copiar a lista'">Copiar a lista</span>
                        </x-botao>
                        <a href="{{ route('plantao') }}" class="py-2 text-center text-sm font-medium text-marca-700 hover:underline sm:ml-auto">
                            Ver a mensagem, outro dia ou incluir sobreaviso
                        </a>
                    </div>
                @endif
            </x-cartao>
        </div>

        {{-- ------------------------------------------------------------
             Coluna lateral: aniversariantes, escala e pendências
             ------------------------------------------------------------ --}}

        <div class="space-y-5">

            {{-- O cartão inteiro é o link: alvo grande, fácil de acertar no celular. --}}
            <a
                href="{{ route('aniversariantes') }}"
                class="group block rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-marca-300 sm:px-5"
            >
                <div class="flex items-center gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full {{ $aniversariantesHoje->isNotEmpty() ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500' }}">
                        <x-icone nome="bolo" class="size-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-slate-900">Aniversariantes</p>
                        <p class="text-xs text-slate-500">
                            @if ($aniversariantesHoje->isNotEmpty())
                                {{ $aniversariantesHoje->count() }} {{ $aniversariantesHoje->count() === 1 ? 'pessoa faz' : 'pessoas fazem' }} aniversário hoje
                            @else
                                Ninguém faz aniversário hoje
                            @endif
                        </p>
                    </div>
                    <x-icone nome="seta-direita" class="size-4 shrink-0 text-slate-400 transition group-hover:translate-x-0.5" />
                </div>

                @if ($aniversariantesHoje->isNotEmpty())
                    <p class="mt-3 text-sm font-medium text-slate-800">
                        🎂 {{ $aniversariantesHoje->pluck('nome_curto')->implode(', ') }}
                    </p>
                @elseif ($proximoAniversariante)
                    <p class="mt-3 text-xs text-slate-600">
                        Próximo: <strong class="text-slate-800">{{ $proximoAniversariante['motorista']->nome_curto }}</strong>,
                        em {{ $proximoAniversariante['data']->format('d/m') }}
                        ({{ $proximoAniversariante['data']->translatedFormat('l') }})
                    </p>
                @endif
            </a>

            {{-- Escala do mês e a do mês seguinte --}}
            <x-cartao titulo="Escalas">
                <div class="-my-1 divide-y divide-slate-100">
                    @foreach ([
                        ['escala' => $escalaAtual, 'erros' => $errosDaEscalaAtual, 'referencia' => $hoje, 'propostas' => $propostasDeHoje],
                        ['escala' => $proximaEscala, 'erros' => $errosDaProximaEscala, 'referencia' => $proximoMes, 'propostas' => $propostasDoProximoMes],
                    ] as $item)
                        <div class="flex items-center gap-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-slate-900">{{ ucfirst($item['referencia']->translatedFormat('F \d\e Y')) }}</p>
                                @if ($item['propostas']->isNotEmpty())
                                    <p class="text-xs text-amber-700">
                                        {{ $item['propostas']->count() }} propostas aguardando decisão
                                    </p>
                                @elseif ($item['escala'])
                                    <p class="text-xs {{ $item['erros'] > 0 ? 'text-rose-600' : 'text-slate-500' }}">
                                        {{ $item['escala']->status->rotulo() }}
                                        @if ($item['erros'] > 0)
                                            · {{ $item['erros'] }} {{ $item['erros'] === 1 ? 'pendência' : 'pendências' }} para publicar
                                        @endif
                                    </p>
                                @else
                                    <p class="text-xs text-slate-500">Ainda não foi criada</p>
                                @endif
                            </div>

                            @if ($item['propostas']->isNotEmpty())
                                <x-botao href="{{ route('escalas.comparar', $item['propostas']->first()) }}" variante="secundario" tamanho="pequeno">
                                    Comparar
                                </x-botao>
                            @elseif ($item['escala'])
                                <x-botao href="{{ route('escalas.show', $item['escala']) }}" variante="secundario" tamanho="pequeno">
                                    Abrir
                                </x-botao>
                            @elseif (auth()->user()->podeEditar())
                                <x-botao href="{{ route('escalas.create') }}" variante="suave" tamanho="pequeno" icone="mais">
                                    Criar
                                </x-botao>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-cartao>

            {{-- Pendências de cadastro: só aparecem quando existem --}}
            @php
                $listaPendencias = [
                    ['chave' => 'cnh_vencida', 'rotulo' => 'CNH vencida', 'cor' => 'text-rose-700 bg-rose-50 ring-rose-200'],
                    ['chave' => 'contrato_encerrado', 'rotulo' => 'Contrato encerrado', 'cor' => 'text-rose-700 bg-rose-50 ring-rose-200'],
                    ['chave' => 'cnh_vencendo', 'rotulo' => 'CNH a vencer em 60 dias', 'cor' => 'text-amber-700 bg-amber-50 ring-amber-200'],
                    ['chave' => 'contrato_vencendo', 'rotulo' => 'Contrato a encerrar em 30 dias', 'cor' => 'text-amber-700 bg-amber-50 ring-amber-200'],
                    ['chave' => 'sem_telefone', 'rotulo' => 'Sem telefone cadastrado', 'cor' => 'text-slate-700 bg-slate-50 ring-slate-200'],
                ];
                $comPendencia = collect($listaPendencias)->filter(fn ($p) => $pendencias[$p['chave']]->isNotEmpty());
            @endphp

            @if ($comPendencia->isNotEmpty())
                <x-cartao titulo="Pendências do cadastro">
                    <div class="-my-1 divide-y divide-slate-100">
                        @foreach ($comPendencia as $item)
                            <div class="py-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-medium text-slate-700">{{ $item['rotulo'] }}</p>
                                    <span class="rounded-full px-1.5 py-0.5 text-[0.7rem] font-semibold ring-1 ring-inset tabular-nums {{ $item['cor'] }}">
                                        {{ $pendencias[$item['chave']]->count() }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $pendencias[$item['chave']]->take(3)->pluck('nome_curto')->implode(', ') }}@if ($pendencias[$item['chave']]->count() > 3), …@endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                </x-cartao>
            @endif
        </div>
    </div>
</x-layouts.app>
