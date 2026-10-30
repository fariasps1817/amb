@php
    use App\Support\Telefone;

    $abaInicial = $errors->hasAny(['motivo_nao_atendimento']) || old('_acao') === 'nao_atendida' ? 'nao' : 'sim';
    $telefonesDoPaciente = array_filter([$solicitacao->contato_telefone_1, $solicitacao->contato_telefone_2]);
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
                    <x-campo-leitura rotulo="Telefone" :valor="$solicitacao->telefoneSolicitante()" />
                </dl>

                <x-botao href="tel:{{ $solicitacao->solicitante_telefone }}" tamanho="grande" icone="telefone" class="mt-4 w-full">
                    Ligar para {{ Str::before($solicitacao->solicitante_nome, ' ') }}
                </x-botao>

                @if ($solicitacao->estaAguardando())
                    <p class="mt-2 text-center text-xs text-slate-500">
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
                </dl>

                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($telefonesDoPaciente as $telefone)
                        <x-botao href="tel:{{ $telefone }}" variante="secundario" tamanho="grande" icone="telefone">
                            {{ Telefone::formatar($telefone) }}
                        </x-botao>
                    @endforeach
                </div>
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
            <div
                x-data="{
                    texto: @js($textoParaMotorista),
                    destino: '',
                    copiado: false,
                    podeCompartilhar: typeof navigator.share === 'function',
                }"
            >
            <x-cartao titulo="Enviar para o motorista" descricao="Vai pelo seu WhatsApp, com os dados da viagem.">
                <div class="space-y-2">
                    <x-select nome="destino_whatsapp" rotulo="Motorista" vazio="Selecione o motorista" x-model="destino">
                        @if ($deServico->isNotEmpty())
                            <optgroup label="De plantão agora">
                                @foreach ($deServico as $plantao)
                                    @php $numero = Telefone::paraWhatsapp($plantao->motorista->telefone_1); @endphp
                                    <option value="{{ $numero }}" @disabled($numero === null)>
                                        {{ $plantao->motorista->nome_curto }} · {{ $plantao->posto?->rotuloLotacao() }}{{ $numero === null ? ' (sem telefone)' : '' }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        <optgroup label="Demais motoristas">
                            @foreach ($demaisMotoristas as $motorista)
                                @php $numero = Telefone::paraWhatsapp($motorista->telefone_1); @endphp
                                <option value="{{ $numero }}" @disabled($numero === null)>
                                    {{ $motorista->nome_curto }}{{ $numero === null ? ' (sem telefone)' : '' }}
                                </option>
                            @endforeach
                        </optgroup>
                    </x-select>

                    {{-- Link montado na hora do toque, com o texto já editado. --}}
                    <a
                        :href="destino ? 'https://wa.me/' + destino + '?text=' + encodeURIComponent(texto) : null"
                        :aria-disabled="destino ? 'false' : 'true'"
                        :class="destino ? '' : 'pointer-events-none opacity-50'"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-marca-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-marca-700"
                    >
                        <x-icone nome="whatsapp" class="size-4 shrink-0" />
                        Enviar para este motorista
                    </a>
                    <p class="text-xs text-slate-500">Para mandar a mais de um, escolha outro motorista e toque de novo.</p>
                </div>

                <div class="mt-4 grid gap-2 border-t border-slate-100 pt-4 sm:flex sm:flex-wrap">
                    {{-- Sem número: o WhatsApp abre para escolher a conversa ou o grupo. --}}
                    <a
                        :href="'https://wa.me/?text=' + encodeURIComponent(texto)"
                        href="https://wa.me/?text={{ rawurlencode($textoParaMotorista) }}"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50"
                    >
                        <x-icone nome="whatsapp" class="size-4 shrink-0" />
                        Escolher no WhatsApp
                    </a>

                    {{-- No celular abre a lista de apps do aparelho, que permite
                         mandar a vários de uma vez. --}}
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

                    <x-botao
                        x-on:click="copiarTexto(texto).then(ok => { copiado = ok; setTimeout(() => copiado = false, 2000) })"
                        variante="secundario"
                        tamanho="grande"
                        icone="copiar"
                    >
                        <span x-text="copiado ? 'Copiado!' : 'Copiar texto'">Copiar texto</span>
                    </x-botao>
                </div>

                <details class="mt-3">
                    <summary class="cursor-pointer py-1 text-sm font-medium text-marca-700">Ver ou editar a mensagem</summary>
                    <label for="texto-para-motorista" class="sr-only">Texto da mensagem</label>
                    <textarea
                        id="texto-para-motorista"
                        x-model="texto"
                        rows="16"
                        class="mt-2 block w-full rounded-lg border-0 bg-slate-50 px-3 py-2.5 font-mono text-[0.8rem] leading-relaxed text-slate-800 ring-1 ring-inset ring-slate-200 focus:bg-white focus:ring-2 focus:ring-marca-600"
                    ></textarea>
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

        {{-- Origem do envio: para investigar abuso da página aberta --}}
        @if (auth()->user()->ehAdmin() && $solicitacao->ip)
            <p class="text-xs text-slate-400">Enviada a partir de {{ $solicitacao->ip }}</p>
        @endif
    </div>
</x-layouts.app>
