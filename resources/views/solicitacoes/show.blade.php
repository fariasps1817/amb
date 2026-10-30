@php
    use App\Support\Telefone;

    $abaInicial = $errors->hasAny(['motivo_nao_atendimento']) || old('_acao') === 'nao_atendida' ? 'nao' : 'sim';

    // Os dois grupos da lista de envio, no mesmo formato.
    $gruposDeMotoristas = [
        'De plantão agora' => $deServico->map(fn ($plantao) => [
            'nome' => $plantao->motorista->nome_curto,
            'lotacao' => $plantao->posto?->rotuloLotacao(),
            'numero' => Telefone::paraWhatsapp($plantao->motorista->telefone_1),
        ]),
        'Demais motoristas' => $demaisMotoristas->map(fn ($motorista) => [
            'nome' => $motorista->nome_curto,
            'lotacao' => null,
            'numero' => Telefone::paraWhatsapp($motorista->telefone_1),
        ]),
    ];
@endphp

<x-layouts.app :titulo="'Solicitação '.$solicitacao->protocolo" :subtitulo="$solicitacao->tipo->rotulo()">
    <x-slot:acoes>
        <x-botao href="{{ route('solicitacoes.index') }}" variante="secundario" tamanho="pequeno" icone="seta-esquerda">
            Voltar à lista
        </x-botao>
    </x-slot:acoes>

    <div class="mx-auto max-w-4xl space-y-5">

        {{-- ------------------------------------------------------------
             Situação
             ------------------------------------------------------------ --}}

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <x-badge :cor="$solicitacao->status->corBadge()">{{ $solicitacao->status->rotulo() }}</x-badge>
            <p class="text-sm text-slate-700">
                Recebida em <strong>{{ $solicitacao->solicitada_em->format('d/m/Y \à\s H:i') }}</strong>
                @if ($solicitacao->estaAguardando())
                    · <span class="font-medium text-rose-700">aguardando {{ $solicitacao->solicitada_em->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                @endif
            </p>
        </div>

        @if ($solicitacao->duplicadaDe)
            <div class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                <x-icone nome="alerta" class="mt-0.5 size-5 shrink-0 text-amber-600" />
                <p>
                    <strong>Possível duplicada:</strong> mesmo paciente e telefone da solicitação
                    <a href="{{ route('solicitacoes.show', $solicitacao->duplicadaDe) }}" class="font-semibold underline">{{ $solicitacao->duplicadaDe->protocolo }}</a>,
                    de {{ $solicitacao->duplicadaDe->solicitada_em->format('d/m H:i') }}. Confira antes de mandar a ambulância.
                </p>
            </div>
        @endif

        {{-- Desfecho já registrado --}}
        @unless ($solicitacao->estaAguardando())
            <x-cartao :titulo="$solicitacao->foiAtendida() ? 'Atendida' : 'Não atendida'">
                <dl class="grid gap-4 sm:grid-cols-2">
                    @if ($solicitacao->foiAtendida())
                        <x-campo-leitura rotulo="Motorista" :valor="$solicitacao->motorista?->nome_curto" />
                        <x-campo-leitura rotulo="Data e hora do atendimento" :valor="$solicitacao->atendida_em?->format('d/m/Y H:i')" />
                    @else
                        <x-campo-leitura rotulo="Motivo" :valor="$solicitacao->motivo_nao_atendimento?->rotulo()" />
                    @endif
                    <div class="sm:col-span-2">
                        <x-campo-leitura rotulo="Observações" :valor="$solicitacao->observacoes_atendimento" />
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                    <p class="text-xs text-slate-500">
                        Registrado por {{ $solicitacao->registradaPor?->nome ?? '—' }}
                        em {{ $solicitacao->registrada_em?->format('d/m/Y H:i') }}
                    </p>
                    <form
                        method="POST"
                        action="{{ route('solicitacoes.reabrir', $solicitacao) }}"
                        onsubmit="return confirm('Reabrir esta solicitação? O registro do atendimento será apagado e ela voltará a aguardar.')"
                    >
                        @csrf
                        <x-botao type="submit" variante="secundario" tamanho="pequeno" icone="atualizar">Reabrir</x-botao>
                    </form>
                </div>
            </x-cartao>
        @endunless

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

            {{-- ------------------------------------------------------------
                 Hospital e solicitante: o contato para confirmar
                 ------------------------------------------------------------ --}}

            <x-cartao titulo="Hospital e solicitante">
                <dl class="space-y-3">
                    <x-campo-leitura rotulo="Hospital" :valor="$solicitacao->hospital_nome" />
                    <x-campo-leitura rotulo="Cidade e bairro" :valor="$solicitacao->hospital_cidade.' · '.$solicitacao->hospital_bairro" />
                    <x-campo-leitura rotulo="Solicitante" :valor="$solicitacao->solicitante_nome.' | '.$solicitacao->solicitante_funcao" />
                    <x-campo-telefone :numero="$solicitacao->solicitante_telefone" />
                </dl>

                @if ($solicitacao->estaAguardando())
                    <p class="mt-3 text-xs text-slate-500">
                        A página é aberta: confirme por telefone antes de mandar a ambulância.
                    </p>
                @endif
            </x-cartao>

            {{-- ------------------------------------------------------------
                 Paciente
                 ------------------------------------------------------------ --}}

            <x-cartao titulo="Paciente">
                <dl class="space-y-3">
                    <x-campo-leitura rotulo="Nome" :valor="$solicitacao->paciente_nome" />
                    <x-campo-leitura rotulo="Idade" :valor="$solicitacao->idadeTexto()" />
                    <x-campo-leitura rotulo="Acompanhante" :valor="$solicitacao->acompanhante_nome" />
                    <x-campo-telefone :numero="$solicitacao->contato_telefone_1" />
                    @if (filled($solicitacao->contato_telefone_2))
                        <x-campo-telefone rotulo="Outro telefone" :numero="$solicitacao->contato_telefone_2" />
                    @endif
                </dl>
            </x-cartao>

            {{-- ------------------------------------------------------------
                 Destino
                 ------------------------------------------------------------ --}}

            <x-cartao titulo="Para onde levar">
                <dl class="space-y-3">
                    <x-campo-leitura rotulo="Endereço" :valor="$solicitacao->destino_endereco" />
                    <x-campo-leitura rotulo="Bairro ou localidade" :valor="$solicitacao->destino_bairro" />
                    <x-campo-leitura rotulo="Ponto de referência" :valor="$solicitacao->destino_referencia" />
                    <x-campo-leitura rotulo="Município" :valor="$solicitacao->destino_municipio" />
                </dl>
            </x-cartao>

            {{-- ------------------------------------------------------------
                 Situação do paciente
                 ------------------------------------------------------------ --}}

            <x-cartao titulo="Situação do paciente">
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($solicitacao->situacaoEmEtiquetas() as $etiqueta)
                        <span class="rounded-md bg-slate-100 px-2.5 py-1 text-sm text-slate-800">{{ $etiqueta }}</span>
                    @endforeach
                </div>

                @if (filled($solicitacao->observacoes_paciente))
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-700">{{ $solicitacao->observacoes_paciente }}</p>
                @endif
            </x-cartao>
        </div>

        {{-- ------------------------------------------------------------
             Enviar para o motorista pelo WhatsApp. O texto vem pronto e pode
             ser editado; a tela continua aberta, para mandar a mais de um.
             ------------------------------------------------------------ --}}

        @if ($solicitacao->estaAguardando())
            {{-- Em uma div comum: o @js não é processado dentro da tag de um componente. --}}
            <div x-data="{ texto: @js($textoParaMotorista), listaAberta: false, abertos: [], copiado: false }">
            <x-cartao titulo="Enviar para o motorista" descricao="Vai pelo seu WhatsApp, com os dados da viagem.">
                <x-botao x-on:click="listaAberta = ! listaAberta" x-bind:aria-expanded="listaAberta" tamanho="grande" class="w-full">
                    <x-icone nome="whatsapp-marca" class="size-4 shrink-0 fill-current stroke-none" />
                    Enviar mensagem
                    <x-icone nome="seta-baixo" class="size-4 shrink-0 transition" x-bind:class="listaAberta && 'rotate-180'" />
                </x-botao>

                <div x-show="listaAberta" x-cloak class="mt-3 space-y-3">
                    {{-- Rolagem própria: com a lista inteira na página, o resto da tela fica longe. --}}
                    <div class="max-h-80 space-y-3 overflow-y-auto">
                        @foreach ($gruposDeMotoristas as $grupo => $motoristas)
                            @continue($motoristas->isEmpty())

                            <div>
                                <p class="mb-1 text-xs font-medium text-slate-500">{{ $grupo }}</p>
                                <ul class="divide-y divide-slate-100 rounded-lg ring-1 ring-inset ring-slate-200">
                                    @foreach ($motoristas as $motorista)
                                        <li>
                                            @if ($motorista['numero'])
                                                {{-- O endereço é montado na tela, com o texto já editado. A marca serve para
                                                     não repetir, ao mandar a vários: não confirma que foi enviada. --}}
                                                <a
                                                    :href="'https://wa.me/{{ $motorista['numero'] }}?text=' + encodeURIComponent(texto)"
                                                    x-on:click="abertos.push('{{ $motorista['numero'] }}')"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="flex min-h-11 items-center justify-between gap-3 px-3 py-2 text-sm transition hover:bg-slate-50"
                                                >
                                                    <span class="min-w-0">
                                                        <span class="font-medium text-slate-900">{{ $motorista['nome'] }}</span>
                                                        @if ($motorista['lotacao'])
                                                            <span class="text-slate-500">· {{ $motorista['lotacao'] }}</span>
                                                        @endif
                                                    </span>
                                                    <span class="flex shrink-0 items-center gap-1.5 text-emerald-600">
                                                        <x-icone nome="check" class="size-4" x-show="abertos.includes('{{ $motorista['numero'] }}')" x-cloak />
                                                        <x-icone nome="whatsapp-marca" class="size-4 fill-current stroke-none" />
                                                    </span>
                                                </a>
                                            @else
                                                <span class="flex min-h-11 items-center justify-between gap-3 px-3 py-2 text-sm text-slate-400">
                                                    {{ $motorista['nome'] }}
                                                    <span class="shrink-0 text-xs">sem telefone</span>
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>

                    {{-- Sem número: o WhatsApp abre para escolher a conversa ou o grupo. --}}
                    <a
                        :href="'https://wa.me/?text=' + encodeURIComponent(texto)"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50"
                    >
                        <x-icone nome="whatsapp-marca" class="size-4 shrink-0 fill-current stroke-none text-emerald-600" />
                        Outra pessoa dos meus contatos
                    </a>
                </div>

                <details class="mt-3">
                    <summary class="cursor-pointer py-1 text-sm font-medium text-marca-700">Ver ou editar a mensagem</summary>
                    <label for="texto-para-motorista" class="sr-only">Texto da mensagem</label>
                    <textarea
                        id="texto-para-motorista"
                        x-model="texto"
                        rows="20"
                        class="mt-2 block w-full rounded-lg border-0 bg-slate-50 px-3 py-2.5 font-mono text-[0.8rem] leading-relaxed text-slate-800 ring-1 ring-inset ring-slate-200 focus:bg-white focus:ring-2 focus:ring-marca-600"
                    ></textarea>
                    <div class="mt-1 text-right">
                        <button
                            type="button"
                            x-on:click="copiarTexto(texto).then(ok => { copiado = ok; setTimeout(() => copiado = false, 2000) })"
                            class="inline-flex min-h-9 items-center gap-1.5 rounded px-2 text-xs font-medium text-slate-500 transition hover:text-slate-700"
                        >
                            <x-icone nome="copiar" class="size-4" />
                            <span x-text="copiado ? 'Copiado!' : 'Copiar texto'">Copiar texto</span>
                        </button>
                    </div>
                </details>
            </x-cartao>
            </div>
        @endif

        {{-- ------------------------------------------------------------
             Registrar o desfecho
             ------------------------------------------------------------ --}}

        @if ($solicitacao->estaAguardando())
            <x-cartao titulo="Registrar o desfecho" x-data="{ aba: '{{ $abaInicial }}' }">
                <div class="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        x-on:click="aba = 'sim'"
                        class="min-h-11 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset transition"
                        :class="aba === 'sim' ? 'bg-emerald-50 text-emerald-900 ring-emerald-300' : 'bg-white text-slate-700 ring-slate-300'"
                    >
                        Foi atendida
                    </button>
                    <button
                        type="button"
                        x-on:click="aba = 'nao'"
                        class="min-h-11 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset transition"
                        :class="aba === 'nao' ? 'bg-slate-100 text-slate-900 ring-slate-400' : 'bg-white text-slate-700 ring-slate-300'"
                    >
                        Não foi atendida
                    </button>
                </div>

                <form method="POST" action="{{ route('solicitacoes.atendida', $solicitacao) }}" class="mt-4 space-y-4" x-show="aba === 'sim'">
                    @csrf

                    <x-select nome="motorista_id" rotulo="Motorista que foi" vazio="Selecione o motorista" required obrigatorio>
                        @if ($deServico->isNotEmpty())
                            <optgroup label="De plantão agora">
                                @foreach ($deServico as $plantao)
                                    <option value="{{ $plantao->motorista_id }}" @selected((string) old('motorista_id') === (string) $plantao->motorista_id)>
                                        {{ $plantao->motorista->nome_curto }} · {{ $plantao->posto?->rotuloLotacao() }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        <optgroup label="Demais motoristas">
                            @foreach ($demaisMotoristas as $motorista)
                                <option value="{{ $motorista->id }}" @selected((string) old('motorista_id') === (string) $motorista->id)>
                                    {{ $motorista->nome_curto }}
                                </option>
                            @endforeach
                        </optgroup>
                    </x-select>

                    <x-input
                        nome="atendida_em"
                        tipo="datetime-local"
                        rotulo="Data e hora do atendimento"
                        :value="old('atendida_em', now()->format('Y-m-d\TH:i'))"
                        required
                        obrigatorio
                    />

                    <x-textarea nome="observacoes_atendimento" rotulo="Observações" linhas="3" maxlength="1000">{{ old('observacoes_atendimento') }}</x-textarea>

                    <x-botao type="submit" tamanho="grande" icone="check" class="w-full sm:w-auto">Registrar como atendida</x-botao>
                </form>

                <form method="POST" action="{{ route('solicitacoes.nao-atendida', $solicitacao) }}" class="mt-4 space-y-4" x-show="aba === 'nao'" x-cloak>
                    @csrf
                    <input type="hidden" name="_acao" value="nao_atendida">

                    <x-select nome="motivo_nao_atendimento" rotulo="Motivo" vazio="Selecione o motivo" :opcoes="$motivos" required obrigatorio />

                    <div>
                        <label for="obs-nao-atendida" class="mb-1.5 block text-sm font-medium text-slate-700">Observações</label>
                        <textarea
                            id="obs-nao-atendida"
                            name="observacoes_atendimento"
                            rows="3"
                            maxlength="1000"
                            class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-marca-600 sm:text-sm"
                        >{{ old('observacoes_atendimento') }}</textarea>
                        @error('observacoes_atendimento')
                            <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-botao type="submit" variante="secundario" tamanho="grande" class="w-full sm:w-auto">Registrar como não atendida</x-botao>
                </form>
            </x-cartao>
        @endif

        {{-- Só do Administrador: origem do envio, para investigar abuso da página aberta, e apagar. --}}
        @if (auth()->user()->ehAdmin())
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-slate-400">
                    @if ($solicitacao->ip)
                        Enviada a partir de {{ $solicitacao->ip }}
                    @endif
                </p>
                <form
                    method="POST"
                    action="{{ route('solicitacoes.destroy', $solicitacao) }}"
                    onsubmit="return confirm('Apagar a solicitação {{ $solicitacao->protocolo }}? Ela sai do sistema de vez e isso não pode ser desfeito.')"
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        title="Apagar solicitação"
                        aria-label="Apagar solicitação"
                        class="flex size-11 items-center justify-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                    >
                        <x-icone nome="lixeira" class="size-5" />
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
