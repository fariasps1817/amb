@php
    $nomesDrivers = [
        'link' => 'Link wa.me (envio manual)',
        'cloud' => 'WhatsApp Cloud API (Meta)',
        'evolution' => 'Evolution API',
    ];
    $nomeDriver = $nomesDrivers[$driver->nome()] ?? $driver->nome();
@endphp

<x-layouts.app
    :titulo="'Mensagens · '.$escala->referenciaLonga()"
    subtitulo="Aviso individual de plantão pelo WhatsApp"
>
    <x-slot:acoes>
        <x-botao href="{{ route('documentos.index', $escala) }}" variante="secundario" tamanho="pequeno" icone="seta-esquerda">
            <span class="hidden sm:inline">Documentos</span>
        </x-botao>
    </x-slot:acoes>

    <div class="mx-auto max-w-4xl space-y-5">

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-indicador rotulo="Mensagens" :valor="$totais['total']" icone="whatsapp" :rodape="$totais['escalados'].' escalados'" />
            <x-indicador rotulo="Enviadas" :valor="$totais['enviadas']" icone="check" />
            <x-indicador rotulo="Pendentes" :valor="$totais['pendentes']" icone="alerta" />
            <x-indicador rotulo="Com erro" :valor="$totais['erros']" icone="erro" />
        </div>

        {{-- ------------------------------------------------------------
             Modo de envio e ações
             ------------------------------------------------------------ --}}

        <x-cartao titulo="Modo de envio" :descricao="$nomeDriver">
            @if ($pendenciaDriver)
                <p class="mb-3 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <x-icone nome="erro" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ $pendenciaDriver }}</span>
                </p>
            @elseif ($driverManual)
                <p class="mb-3 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900 ring-1 ring-inset ring-sky-200">
                    Cada botão <strong>Abrir WhatsApp</strong> abre a conversa do motorista com o texto já preenchido —
                    você confere e aperta enviar. Depois use <strong>Confirmar envio</strong> para registrar.
                    Para disparo automático em lote, configure uma API no arquivo <code>.env</code>.
                </p>
            @endif

            <div class="flex flex-wrap items-center gap-2">
                @if (auth()->user()->podeEditar())
                    <form method="POST" action="{{ route('mensagens.preparar', $escala) }}">
                        @csrf
                        <x-botao type="submit" tamanho="pequeno" icone="atualizar">
                            {{ $totais['total'] === 0 ? 'Preparar mensagens' : 'Atualizar pendentes' }}
                        </x-botao>
                    </form>

                    @if ($totais['total'] > 0)
                        <form
                            method="POST"
                            action="{{ route('mensagens.preparar', $escala) }}"
                            onsubmit="return confirm('Recriar TODAS as mensagens, inclusive as já enviadas? O registro de envio anterior será perdido.')"
                        >
                            @csrf
                            <input type="hidden" name="recriar_enviadas" value="1">
                            <x-botao type="submit" variante="secundario" tamanho="pequeno">
                                Recriar todas
                            </x-botao>
                        </form>
                    @endif

                    @unless ($driverManual)
                        <form
                            method="POST"
                            action="{{ route('mensagens.enviar-todas', $escala) }}"
                            onsubmit="return confirm('Enviar as {{ $totais['pendentes'] + $totais['erros'] }} mensagem(ns) pendente(s)?')"
                        >
                            @csrf
                            <x-botao type="submit" variante="suave" tamanho="pequeno" icone="raio">
                                Enviar todas as pendentes
                            </x-botao>
                        </form>
                    @endunless
                @endif
            </div>
        </x-cartao>

        {{-- Sem telefone não há como comunicar a escala --}}
        @if ($semTelefone->isNotEmpty())
            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <x-icone nome="alerta" class="mt-0.5 size-5 shrink-0" />
                <div>
                    <p class="font-medium">
                        {{ $semTelefone->count() }} motorista(s) escalado(s) sem telefone válido
                    </p>
                    <p class="mt-0.5">
                        {{ $semTelefone->pluck('nome_curto')->implode(', ') }} — cadastre o número para que recebam a escala.
                    </p>
                </div>
            </div>
        @endif

        {{-- ------------------------------------------------------------
             Lista de mensagens
             ------------------------------------------------------------ --}}

        @forelse ($mensagens as $mensagem)
            <div
                class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200"
                x-data="{ aberto: false, copiado: false }"
            >
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-slate-900">{{ $mensagem->motorista?->nome_curto }}</span>
                            <x-badge :cor="$mensagem->corBadge()">{{ $mensagem->statusRotulo() }}</x-badge>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ $mensagem->telefoneFormatado() ?: 'sem telefone' }}
                            @if ($mensagem->enviada_em)
                                · enviada em {{ $mensagem->enviada_em->format('d/m/Y H:i') }}
                                @if ($mensagem->operador)
                                    por {{ $mensagem->operador->primeiroNome() }}
                                @endif
                            @endif
                        </p>
                        @if ($mensagem->comErro() && $mensagem->retorno)
                            <p class="mt-1 text-xs text-rose-600">{{ Str::limit($mensagem->retorno, 140) }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <x-botao x-on:click="aberto = ! aberto" variante="texto" tamanho="pequeno" icone="olho">
                            <span x-text="aberto ? 'Ocultar' : 'Ver texto'">Ver texto</span>
                        </x-botao>

                        {{-- Copia da própria prévia, que já traz o texto exato
                             que o motorista recebe. --}}
                        <x-botao
                            x-on:click="copiarTexto($refs.texto.textContent).then(ok => { copiado = ok; setTimeout(() => copiado = false, 2000) })"
                            variante="texto"
                            tamanho="pequeno"
                            icone="copiar"
                            title="Copiar a mensagem inteira"
                        >
                            <span x-text="copiado ? 'Copiado!' : 'Copiar'">Copiar</span>
                        </x-botao>

                        @if (auth()->user()->podeEditar())
                            @if ($driverManual)
                                @if ($link = $mensagem->link())
                                    <x-botao
                                        :href="$link"
                                        target="_blank"
                                        rel="noopener"
                                        tamanho="pequeno"
                                        icone="whatsapp"
                                        :variante="$mensagem->foiEnviada() ? 'secundario' : 'primario'"
                                    >
                                        Abrir WhatsApp
                                    </x-botao>
                                @else
                                    <span class="text-xs text-rose-600">telefone inválido</span>
                                @endif

                                @unless ($mensagem->foiEnviada())
                                    <form method="POST" action="{{ route('mensagens.enviada', [$escala, $mensagem]) }}">
                                        @csrf
                                        <x-botao type="submit" variante="secundario" tamanho="pequeno" icone="check">
                                            Confirmar envio
                                        </x-botao>
                                    </form>
                                @endunless
                            @else
                                <form method="POST" action="{{ route('mensagens.enviar', [$escala, $mensagem]) }}">
                                    @csrf
                                    <x-botao
                                        type="submit"
                                        tamanho="pequeno"
                                        icone="whatsapp"
                                        :variante="$mensagem->foiEnviada() ? 'secundario' : 'primario'"
                                    >
                                        {{ $mensagem->foiEnviada() ? 'Reenviar' : 'Enviar' }}
                                    </x-botao>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- Prévia do texto exatamente como o motorista vai receber --}}
                <div x-show="aberto" x-collapse class="border-t border-slate-200 bg-slate-50 px-4 py-3" style="display: none">
                    <pre x-ref="texto" class="whitespace-pre-wrap font-sans text-xs leading-relaxed text-slate-700">{{ $mensagem->texto }}</pre>
                </div>
            </div>
        @empty
            <x-cartao>
                <div class="py-8 text-center">
                    <span class="mx-auto flex size-11 items-center justify-center rounded-full bg-slate-100">
                        <x-icone nome="whatsapp" class="size-6 text-slate-400" />
                    </span>
                    <h3 class="mt-3 text-sm font-semibold text-slate-900">Nenhuma mensagem preparada</h3>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                        O sistema monta um texto para cada motorista escalado, listando os dias de plantão, a unidade
                        e a ambulância.
                    </p>
                    @if (auth()->user()->podeEditar())
                        <form method="POST" action="{{ route('mensagens.preparar', $escala) }}" class="mt-4">
                            @csrf
                            <x-botao type="submit" icone="atualizar">Preparar mensagens</x-botao>
                        </form>
                    @endif
                </div>
            </x-cartao>
        @endforelse
    </div>
</x-layouts.app>
