<x-layouts.app titulo="Painel" :subtitulo="now()->translatedFormat('l, d \d\e F \d\e Y')">
    <x-slot:acoes>
        <x-botao href="{{ route('escalas.index') }}" variante="secundario" icone="escalas" tamanho="pequeno">
            <span class="hidden sm:inline">Escalas</span>
        </x-botao>
    </x-slot:acoes>

    <div class="space-y-5">

        {{-- ------------------------------------------------------------
             Números do cadastro
             ------------------------------------------------------------ --}}

        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <x-indicador
                rotulo="Motoristas ativos"
                :valor="$contagens['motoristas']"
                icone="motoristas"
                :rodape="$contagens['motoristas_inativos'] > 0 ? $contagens['motoristas_inativos'].' inativo(s)' : null"
                :href="route('motoristas.index')"
            />
            <x-indicador
                rotulo="Unidades"
                :valor="$contagens['unidades']"
                icone="unidades"
                :href="route('unidades.index')"
            />
            <x-indicador
                rotulo="Ambulâncias"
                :valor="$contagens['ambulancias']"
                icone="ambulancias"
                :href="route('ambulancias.index')"
            />
            <x-indicador
                rotulo="Plantões hoje"
                :valor="$plantoesDeHoje->count()"
                icone="escalas"
                :rodape="$escalaAtual?->referenciaLonga()"
            />
        </div>

        {{-- ------------------------------------------------------------
             Escala do mês corrente
             ------------------------------------------------------------ --}}

        @if ($escalaAtual)
            <x-cartao
                :titulo="'Escala de '.$escalaAtual->referenciaLonga()"
                :descricao="$resumo['plantoes'].' plantões · '.$resumo['postos'].' ambulância(s) em operação'"
            >
                <x-slot:acoes>
                    <x-badge :cor="$escalaAtual->status->corBadge()">{{ $escalaAtual->status->rotulo() }}</x-badge>
                    <x-botao href="{{ route('escalas.show', $escalaAtual) }}" variante="secundario" tamanho="pequeno">
                        Abrir
                    </x-botao>
                </x-slot:acoes>

                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-slate-500">Vagas do mês</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-slate-900 tabular-nums">
                            {{ $resumo['vagas_ocupadas'] }}<span class="text-sm font-normal text-slate-400">/{{ $resumo['vagas_necessarias'] }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">Reserva / apoio</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-slate-900 tabular-nums">{{ $resumo['disponiveis'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">Afastados</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-slate-900 tabular-nums">
                            {{ max(0, $resumo['com_destino'] - $resumo['disponiveis']) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">Sem definição</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums {{ $resumo['sem_definicao'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                            {{ $resumo['sem_definicao'] }}
                        </dd>
                    </div>
                </dl>

                @if ($alertas)
                    <div class="mt-4 space-y-2">
                        @foreach (collect($alertas)->take(4) as $alerta)
                            <div class="flex items-start gap-2.5 rounded-lg border px-3 py-2 text-xs {{ $alerta->classes() }}">
                                <x-icone :nome="$alerta->severidade === 'erro' ? 'erro' : ($alerta->severidade === 'atencao' ? 'alerta' : 'info')" class="mt-px size-4 shrink-0" />
                                <p>{{ $alerta->mensagem }}</p>
                            </div>
                        @endforeach

                        @if (count($alertas) > 4)
                            <p class="text-xs text-slate-500">
                                e mais {{ count($alertas) - 4 }} aviso(s) —
                                <a href="{{ route('escalas.show', $escalaAtual) }}" class="font-medium text-marca-700 hover:underline">ver todos</a>
                            </p>
                        @endif
                    </div>
                @endif
            </x-cartao>
        @else
            <x-cartao>
                <div class="py-6 text-center">
                    <span class="mx-auto flex size-11 items-center justify-center rounded-full bg-slate-100">
                        <x-icone nome="escalas" class="size-6 text-slate-400" />
                    </span>
                    <h3 class="mt-3 text-sm font-semibold text-slate-900">
                        Nenhuma escala para {{ now()->translatedFormat('F \d\e Y') }}
                    </h3>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                        Monte a escala do mês para gerar os plantões, a planilha de distribuição e as mensagens dos motoristas.
                    </p>
                    <x-botao href="{{ route('escalas.create') }}" icone="mais" class="mt-4">
                        Montar escala do mês
                    </x-botao>
                </div>
            </x-cartao>
        @endif

        <div class="grid gap-5 lg:grid-cols-5">

            {{-- --------------------------------------------------------
                 Quem está de plantão hoje
                 -------------------------------------------------------- --}}

            <div class="lg:col-span-3">
                <x-cartao titulo="De plantão hoje" :descricao="now()->format('d/m/Y')" class="h-full">
                    @forelse ($plantoesDeHoje as $plantao)
                        <div class="flex items-center gap-3 border-b border-slate-100 py-2.5 last:border-0 first:pt-0">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-marca-50 text-[0.65rem] font-bold text-marca-700 ring-1 ring-marca-100">
                                {{ Str::limit($plantao->posto?->rotuloLotacao() ?? '', 6, '') }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-900">
                                    {{ $plantao->motorista?->nome_curto }}
                                </p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $plantao->posto?->unidade?->nome }} ·
                                    {{ $plantao->posto?->rotuloPlaca() }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right">
                                @if ($plantao->motorista?->telefone_1)
                                    <a
                                        href="tel:{{ \App\Support\Telefone::digitos($plantao->motorista->telefone_1) }}"
                                        class="text-xs font-medium text-marca-700 hover:underline tabular-nums"
                                    >
                                        {{ $plantao->motorista->telefoneFormatado() }}
                                    </a>
                                @endif
                                <p class="text-[0.7rem] text-slate-400 tabular-nums">
                                    {{ $plantao->horaEntradaTexto() }} às {{ $plantao->horaSaidaTexto() }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-slate-500">
                            Nenhum plantão registrado para hoje.
                        </p>
                    @endforelse
                </x-cartao>
            </div>

            {{-- --------------------------------------------------------
                 Pendências de cadastro
                 -------------------------------------------------------- --}}

            <div class="space-y-5 lg:col-span-2">
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

                <x-cartao titulo="Pendências do cadastro">
                    @forelse ($comPendencia as $item)
                        <div class="border-b border-slate-100 py-2.5 last:border-0 first:pt-0">
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
                    @empty
                        <div class="py-4 text-center">
                            <x-icone nome="check" class="mx-auto size-6 text-emerald-500" />
                            <p class="mt-1.5 text-sm text-slate-600">Cadastros em ordem.</p>
                        </div>
                    @endforelse
                </x-cartao>

                <x-cartao titulo="Últimas escalas">
                    @forelse ($ultimasEscalas as $escala)
                        <a
                            href="{{ route('escalas.show', $escala) }}"
                            class="-mx-2 flex items-center justify-between gap-2 rounded-lg px-2 py-2 transition hover:bg-slate-50"
                        >
                            <span class="text-sm text-slate-700">{{ $escala->referenciaLonga() }}</span>
                            <x-badge :cor="$escala->status->corBadge()">{{ $escala->status->rotulo() }}</x-badge>
                        </a>
                    @empty
                        <p class="py-4 text-center text-sm text-slate-500">Nenhuma escala cadastrada.</p>
                    @endforelse
                </x-cartao>
            </div>
        </div>
    </div>
</x-layouts.app>
