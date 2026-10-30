@php
    $abas = [
        'aguardando' => ['rotulo' => 'Aguardando', 'total' => $totais['aguardando'] ?? 0],
        'atendida' => ['rotulo' => 'Atendidas', 'total' => $totais['atendida'] ?? 0],
        'nao_atendida' => ['rotulo' => 'Não atendidas', 'total' => $totais['nao_atendida'] ?? 0],
        'todas' => ['rotulo' => 'Todas', 'total' => $totais->sum()],
    ];
@endphp

<x-layouts.app titulo="Solicitações de ambulância" subtitulo="Pacientes em alta hospitalar, pedidos pelos hospitais">
    <x-slot:acoes>
        {{-- O link é o que se passa aos hospitais. --}}
        <div x-data="{ copiado: false, link: @js(route('solicitacao.formulario')) }">
            <x-botao
                x-on:click="copiarTexto(link).then(ok => { copiado = ok; setTimeout(() => copiado = false, 2000) })"
                variante="secundario"
                tamanho="pequeno"
                icone="copiar"
            >
                <span x-text="copiado ? 'Link copiado!' : 'Copiar link do formulário'">Copiar link do formulário</span>
            </x-botao>
        </div>
    </x-slot:acoes>

    <div class="mx-auto max-w-4xl space-y-4">

        {{-- Filtros --}}
        <div class="space-y-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200 sm:p-4">
            <nav class="grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Situação das solicitações">
                @foreach ($abas as $valor => $aba)
                    <x-botao
                        href="{{ route('solicitacoes.index', ['situacao' => $valor]) }}"
                        :variante="$situacao === $valor ? 'suave' : 'secundario'"
                        :aria-current="$situacao === $valor ? 'page' : null"
                    >
                        {{ $aba['rotulo'] }}
                        <span class="tabular-nums opacity-60">{{ $aba['total'] }}</span>
                    </x-botao>
                @endforeach
            </nav>

            <form method="GET" class="flex gap-2">
                <input type="hidden" name="situacao" value="{{ $situacao }}">
                <div class="min-w-0 flex-1">
                    <x-input
                        name="busca"
                        tipo="search"
                        :value="request('busca')"
                        placeholder="Protocolo, paciente, hospital ou solicitante"
                        aria-label="Buscar solicitação"
                    />
                </div>
                <x-botao type="submit" variante="secundario" icone="busca">
                    <span class="sr-only">Buscar</span>
                </x-botao>
            </form>
        </div>

        {{-- Lista --}}
        @forelse ($solicitacoes as $solicitacao)
            <a
                href="{{ route('solicitacoes.show', $solicitacao) }}"
                class="block rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-marca-300"
            >
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="font-semibold text-slate-900">{{ $solicitacao->paciente_nome }}</span>
                    <span class="text-xs text-slate-500">{{ $solicitacao->idadeTexto() }}</span>
                    <x-badge :cor="$solicitacao->status->corBadge()">{{ $solicitacao->status->rotulo() }}</x-badge>
                    @if ($solicitacao->possivel_duplicada_de)
                        <x-badge cor="bg-amber-100 text-amber-800 ring-amber-200">possível duplicada</x-badge>
                    @endif
                </div>

                <p class="mt-1 text-sm text-slate-700">{{ $solicitacao->hospital_nome }} · {{ $solicitacao->hospital_cidade }}</p>
                <p class="text-sm text-slate-600">Levar para: {{ $solicitacao->destino_bairro }}, {{ $solicitacao->destino_municipio }}</p>

                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($solicitacao->situacaoEmEtiquetas() as $etiqueta)
                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{{ $etiqueta }}</span>
                    @endforeach
                </div>

                <p class="mt-2 text-xs text-slate-500">
                    {{ $solicitacao->protocolo }} · {{ $solicitacao->solicitada_em->format('d/m/Y H:i') }}
                    @if ($solicitacao->estaAguardando())
                        · <span class="font-medium text-rose-700">aguardando {{ $solicitacao->solicitada_em->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                    @elseif ($solicitacao->foiAtendida())
                        · atendida por {{ $solicitacao->motorista?->nome_curto }} em {{ $solicitacao->atendida_em?->format('d/m H:i') }}
                    @else
                        · {{ mb_strtolower($solicitacao->motivo_nao_atendimento?->rotulo() ?? '') }}
                    @endif
                </p>
            </a>
        @empty
            <div class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
                <span class="mx-auto flex size-11 items-center justify-center rounded-full bg-slate-100">
                    <x-icone nome="sino" class="size-6 text-slate-400" />
                </span>
                <p class="mt-3 text-sm text-slate-600">
                    {{ request('busca') ? 'Nada encontrado para esta busca.' : ($situacao === 'aguardando' ? 'Nenhuma solicitação aguardando atendimento.' : 'Nenhuma solicitação aqui ainda.') }}
                </p>
            </div>
        @endforelse

        @if ($solicitacoes->hasPages())
            <div>{{ $solicitacoes->links() }}</div>
        @endif
    </div>
</x-layouts.app>
