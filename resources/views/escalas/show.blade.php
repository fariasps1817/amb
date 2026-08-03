@php
    $hoje = now()->toDateString();
    $erros = collect($alertas)->filter(fn ($a) => $a->ehErro());
    $avisos = collect($alertas)->reject(fn ($a) => $a->ehErro());
@endphp

<x-layouts.app
    :titulo="'Escala de '.$escala->referenciaLonga()"
    :subtitulo="$resumo['postos'].' ambulância(s) · '.$resumo['plantoes'].' plantões'"
>
    <x-slot:acoes>
        <x-badge :cor="$escala->status->corBadge()">{{ $escala->status->rotulo() }}</x-badge>

        @if ($escala->editavel() && auth()->user()->podeEditar())
            <x-botao href="{{ route('escalas.montar', $escala) }}" variante="secundario" tamanho="pequeno" icone="grade">
                <span class="hidden sm:inline">Montar</span>
            </x-botao>
        @endif

        <x-botao href="{{ route('documentos.index', $escala) }}" tamanho="pequeno" icone="documentos">
            <span class="hidden sm:inline">Documentos</span>
        </x-botao>
    </x-slot:acoes>

    <div class="space-y-5">

        {{-- ------------------------------------------------------------
             Resumo
             ------------------------------------------------------------ --}}

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <x-indicador rotulo="Ambulâncias" :valor="$resumo['postos']" icone="ambulancias" />
            <x-indicador
                rotulo="Vagas preenchidas"
                :valor="$resumo['vagas_ocupadas'].'/'.$resumo['vagas_necessarias']"
                icone="grade"
            />
            <x-indicador rotulo="Plantões" :valor="$resumo['plantoes']" icone="escalas" />
            <x-indicador rotulo="Reserva / apoio" :valor="$resumo['disponiveis']" icone="usuarios" />
            <x-indicador rotulo="Sem definição" :valor="$resumo['sem_definicao']" icone="alerta" />
        </div>

        {{-- ------------------------------------------------------------
             Pendências e ações do ciclo de vida
             ------------------------------------------------------------ --}}

        @if ($erros->isNotEmpty() || $avisos->isNotEmpty())
            <x-cartao :titulo="$erros->isNotEmpty() ? 'Pendências que impedem a publicação' : 'Avisos'">
                <div class="space-y-2">
                    @foreach ($erros->concat($avisos)->take(12) as $alerta)
                        <div class="flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm {{ $alerta->classes() }}">
                            <x-icone
                                :nome="$alerta->severidade === 'erro' ? 'erro' : ($alerta->severidade === 'atencao' ? 'alerta' : 'info')"
                                class="mt-0.5 size-4 shrink-0"
                            />
                            <p>{{ $alerta->mensagem }}</p>
                        </div>
                    @endforeach

                    @if ($erros->count() + $avisos->count() > 12)
                        <p class="text-xs text-slate-500">
                            e mais {{ $erros->count() + $avisos->count() - 12 }} item(ns).
                        </p>
                    @endif
                </div>
            </x-cartao>
        @endif

        @if (auth()->user()->podeEditar())
            <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
                @if ($escala->editavel())
                    <form method="POST" action="{{ route('escalas.gerar', $escala) }}">
                        @csrf
                        <x-botao type="submit" variante="secundario" tamanho="pequeno" icone="atualizar">
                            Regerar plantões
                        </x-botao>
                    </form>
                @endif

                @if ($escala->ehRascunho())
                    <form method="POST" action="{{ route('escalas.publicar', $escala) }}">
                        @csrf
                        {{-- Diretivas como @disabled não funcionam dentro de tags
                             de componente; o atributo vai como bind normal. --}}
                        <x-botao type="submit" tamanho="pequeno" icone="check" :disabled="! $podePublicar">
                            Publicar escala
                        </x-botao>
                    </form>

                    @unless ($podePublicar)
                        <span class="text-xs text-slate-500">
                            Resolva as pendências acima para publicar.
                        </span>
                    @endunless
                @elseif ($escala->publicada())
                    <form method="POST" action="{{ route('escalas.reabrir', $escala) }}">
                        @csrf
                        <x-botao
                            type="submit"
                            variante="secundario"
                            tamanho="pequeno"
                            icone="lapis"
                            onclick="return confirm('Reabrir a escala para ajustes? Os documentos já distribuídos precisarão ser reemitidos.')"
                        >
                            Reabrir para ajustes
                        </x-botao>
                    </form>

                    <form method="POST" action="{{ route('escalas.arquivar', $escala) }}">
                        @csrf
                        <x-botao
                            type="submit"
                            variante="secundario"
                            tamanho="pequeno"
                            onclick="return confirm('Arquivar a escala? Ela ficará somente para consulta.')"
                        >
                            Arquivar
                        </x-botao>
                    </form>
                @endif

                <x-botao href="{{ route('mensagens.index', $escala) }}" variante="secundario" tamanho="pequeno" icone="whatsapp">
                    Mensagens
                </x-botao>

                @if ($escala->ehRascunho())
                    <form
                        method="POST"
                        action="{{ route('escalas.destroy', $escala) }}"
                        class="ml-auto"
                        onsubmit="return confirm('Excluir a escala de {{ $escala->referenciaLonga() }}? Todos os postos e plantões do mês serão apagados.')"
                    >
                        @csrf
                        @method('DELETE')
                        <x-botao type="submit" variante="perigo" tamanho="pequeno" icone="lixeira">Excluir</x-botao>
                    </form>
                @endif
            </div>
        @endif

        {{-- ------------------------------------------------------------
             Planilha: mesmo formato do documento distribuído às unidades
             ------------------------------------------------------------ --}}

        <x-cartao
            titulo="Planilha de plantões"
            :descricao="'Cada X marca o dia de plantão. '.$escala->diasNoMes().' dias no mês.'"
        >
            <x-slot:acoes>
                <x-botao
                    href="{{ route('documentos.planilha', $escala) }}"
                    target="_blank"
                    variante="secundario"
                    tamanho="pequeno"
                    icone="impressora"
                >
                    Imprimir
                </x-botao>
            </x-slot:acoes>

            @if ($escala->postos->isEmpty())
                <p class="py-8 text-center text-sm text-slate-500">
                    Nenhuma ambulância nesta escala.
                    @if (auth()->user()->podeEditar())
                        <a href="{{ route('escalas.montar', $escala) }}" class="font-medium text-marca-700 hover:underline">Montar agora</a>.
                    @endif
                </p>
            @elseif ($resumo['plantoes'] === 0)
                <p class="py-8 text-center text-sm text-slate-500">
                    Os plantões ainda não foram gerados.
                    @if (auth()->user()->podeEditar() && $escala->editavel())
                        Use <strong>Regerar plantões</strong> acima.
                    @endif
                </p>
            @else
                <div class="-mx-4 overflow-x-auto sm:-mx-5 scrollbar-fina">
                    <div class="inline-block min-w-full px-4 sm:px-5">
                        <table class="min-w-full border-collapse text-xs">
                            <thead>
                                {{-- Dias da semana --}}
                                <tr>
                                    <th class="sticky left-0 z-10 border border-slate-300 bg-slate-100 px-2 py-1 text-left font-semibold text-slate-600" colspan="2">
                                        Condutor
                                    </th>
                                    <th class="border border-slate-300 bg-slate-100 px-1 py-1 font-semibold text-slate-600">Fone</th>
                                    @foreach ($dias as $dia)
                                        <th
                                            class="w-7 border border-slate-300 px-0.5 py-1 text-center text-[0.6rem] font-medium {{
                                                $dia->isWeekend() ? 'bg-slate-200 text-slate-600' : 'bg-slate-100 text-slate-500'
                                            }}"
                                        >
                                            {{ mb_substr($dia->translatedFormat('D'), 0, 3) }}
                                        </th>
                                    @endforeach
                                </tr>
                                {{-- Números dos dias --}}
                                <tr>
                                    <th class="sticky left-0 z-10 border border-slate-300 bg-slate-50 px-2 py-1 text-left font-semibold text-slate-700" colspan="2">
                                        {{ $escala->referencia() }}
                                    </th>
                                    <th class="border border-slate-300 bg-slate-50"></th>
                                    @foreach ($dias as $dia)
                                        <th
                                            class="w-7 border border-slate-300 px-0.5 py-1 text-center text-[0.65rem] font-bold tabular-nums {{
                                                $dia->toDateString() === $hoje
                                                    ? 'bg-marca-600 text-white'
                                                    : ($dia->isWeekend() ? 'bg-slate-200 text-slate-700' : 'bg-slate-50 text-slate-700')
                                            }}"
                                        >
                                            {{ $dia->format('d') }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($escala->postos as $posto)
                                    @php $lotacoes = $posto->lotacoes->sortBy('posicao'); @endphp

                                    {{-- Faixa identificando a ambulância e a lotação --}}
                                    <tr>
                                        <td
                                            colspan="{{ 3 + count($dias) }}"
                                            class="border border-slate-300 bg-marca-50 px-2 py-1 font-semibold text-marca-900"
                                        >
                                            <span class="tabular-nums">{{ $posto->rotuloPlaca() }}</span>
                                            <span class="mx-1 text-marca-400">·</span>
                                            {{ $posto->rotuloLotacao() }}
                                            <span class="mx-1 text-marca-400">·</span>
                                            <span class="font-normal">{{ $posto->regimeNotacao() }}</span>
                                            <span class="ml-1 font-normal text-marca-600">
                                                ({{ $posto->unidade?->nome }})
                                            </span>
                                        </td>
                                    </tr>

                                    @foreach ($lotacoes as $lotacao)
                                        @php $motorista = $lotacao->motorista; @endphp

                                        <tr class="hover:bg-slate-50">
                                            <td class="sticky left-0 z-10 w-6 border border-slate-300 bg-white px-1 py-0.5 text-center text-[0.65rem] text-slate-400 tabular-nums">
                                                {{ $lotacao->posicao }}
                                            </td>
                                            <td class="border border-slate-300 bg-white px-2 py-0.5 whitespace-nowrap">
                                                <a href="{{ route('motoristas.show', $motorista) }}" class="text-slate-800 hover:text-marca-700">
                                                    {{ $motorista?->nomePlanilha() }}
                                                </a>
                                            </td>
                                            <td class="border border-slate-300 bg-white px-1.5 py-0.5 whitespace-nowrap text-slate-500 tabular-nums">
                                                {{ $motorista?->telefoneFormatado() }}
                                            </td>

                                            @foreach ($dias as $dia)
                                                @php
                                                    $chave = $dia->toDateString();
                                                    $plantao = $grade[$posto->id][$chave] ?? null;
                                                    $meu = $plantao && $plantao->motorista_id === $lotacao->motorista_id;
                                                @endphp

                                                <td
                                                    class="w-7 border border-slate-300 px-0.5 py-0.5 text-center {{
                                                        $chave === $hoje
                                                            ? 'bg-marca-50'
                                                            : ($dia->isWeekend() ? 'bg-slate-50' : 'bg-white')
                                                    }}"
                                                    @if ($meu)
                                                        title="{{ $motorista?->nome_curto }} — {{ $dia->format('d/m/Y') }} · {{ $plantao->horaEntradaTexto() }} às {{ $plantao->horaSaidaTexto() }}{{ $plantao->ajuste_manual ? ' (troca manual)' : '' }}"
                                                    @endif
                                                >
                                                    @if ($meu)
                                                        <span class="font-bold {{ $plantao->ajuste_manual ? 'text-amber-600' : 'text-slate-900' }}">
                                                            X
                                                        </span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach

                                    {{-- Vagas abertas aparecem para o operador ver a lacuna --}}
                                    @if ($posto->vagasLivres() > 0)
                                        <tr>
                                            <td colspan="3" class="sticky left-0 z-10 border border-slate-300 bg-rose-50 px-2 py-0.5 text-rose-700">
                                                {{ $posto->vagasLivres() }} vaga(s) aberta(s)
                                            </td>
                                            <td colspan="{{ count($dias) }}" class="border border-slate-300 bg-rose-50"></td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-slate-500">
                    <span class="flex items-center gap-1.5">
                        <span class="font-bold text-slate-900">X</span> plantão gerado
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="font-bold text-amber-600">X</span> troca manual
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block size-3 rounded-sm bg-slate-200"></span> fim de semana
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block size-3 rounded-sm bg-marca-600"></span> hoje
                    </span>
                </div>
            @endif
        </x-cartao>

        {{-- ------------------------------------------------------------
             Motoristas fora da escala
             ------------------------------------------------------------ --}}

        @php
            $comDestino = $escala->lotacoes->filter(fn ($l) => ! $l->escalado() && $l->tipo_destino !== null)
                ->sortBy(fn ($l) => $l->motorista?->nome_completo);
            $semDefinicao = $escala->lotacoes->filter(fn ($l) => ! $l->definido())
                ->sortBy(fn ($l) => $l->motorista?->nome_completo);
        @endphp

        @if ($comDestino->isNotEmpty() || $semDefinicao->isNotEmpty())
            <x-cartao
                titulo="Motoristas fora das ambulâncias"
                descricao="Reserva, apoio, férias, licenças e pendências"
            >
                <x-slot:acoes>
                    @if ($escala->editavel() && auth()->user()->podeEditar())
                        <x-botao href="{{ route('escalas.destinos', $escala) }}" variante="secundario" tamanho="pequeno" icone="usuarios">
                            Definir destinos
                        </x-botao>
                    @endif
                </x-slot:acoes>

                @if ($semDefinicao->isNotEmpty())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3">
                        <p class="text-xs font-semibold text-rose-800">
                            {{ $semDefinicao->count() }} motorista(s) sem destino definido
                        </p>
                        <p class="mt-1 text-xs text-rose-700">
                            {{ $semDefinicao->map(fn ($l) => $l->motorista?->nome_curto)->implode(' · ') }}
                        </p>
                    </div>
                @endif

                @if ($comDestino->isNotEmpty())
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($comDestino as $lotacao)
                            <div class="flex items-center gap-2.5 rounded-lg bg-slate-50 px-3 py-2">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-slate-800">{{ $lotacao->motorista?->nome_curto }}</p>
                                    @if ($lotacao->textoOcorrencia())
                                        <p class="truncate text-xs text-slate-500">{{ $lotacao->textoOcorrencia() }}</p>
                                    @endif
                                </div>
                                <x-badge :cor="$lotacao->tipo_destino->corBadge()">
                                    {{ $lotacao->tipo_destino->rotulo() }}
                                </x-badge>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-cartao>
        @endif
    </div>
</x-layouts.app>
