@php
    /** Rotulo amigavel para o endereco de quem esta olhando a propria tela. */
    $meuIp = request()->ip();
@endphp

<x-layouts.app titulo="Acessos" subtitulo="Quem entrou e quem tentou entrar no sistema">

    <div class="space-y-5">

        {{-- Resumo das últimas 24 horas --}}
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Entradas nas últimas 24h</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $entradasNoDia }}</p>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Tentativas sem sucesso</p>
                <p class="mt-1 text-2xl font-semibold {{ $falhasNoDia > 0 ? 'text-amber-700' : 'text-slate-900' }}">
                    {{ $falhasNoDia }}
                </p>
            </div>
        </div>

        {{-- O sinal que realmente importa: uma origem insistindo --}}
        @if ($origensInsistentes->isNotEmpty())
            <x-cartao
                titulo="Origens que mais erraram nas últimas 24h"
                descricao="Um mesmo computador tentando muitas vezes, principalmente com nomes de usuário diferentes, indica tentativa automatizada"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                <th class="py-2 pr-3 font-medium">Endereço de rede</th>
                                <th class="py-2 pr-3 text-right font-medium">Erros</th>
                                <th class="py-2 pr-3 text-right font-medium">Usuários tentados</th>
                                <th class="py-2 font-medium">Última tentativa</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($origensInsistentes as $origem)
                                <tr>
                                    <td class="py-2 pr-3 font-mono text-xs text-slate-800">
                                        {{ $origem->ip }}
                                        @if ($origem->ip === $meuIp)
                                            <span class="font-sans text-xs text-slate-500">(você)</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 text-right font-medium text-slate-900">{{ $origem->total }}</td>
                                    <td class="py-2 pr-3 text-right {{ $origem->usuarios > 2 ? 'font-semibold text-rose-700' : 'text-slate-700' }}">
                                        {{ $origem->usuarios }}
                                    </td>
                                    <td class="py-2 text-slate-600">
                                        {{ \Illuminate\Support\Carbon::parse($origem->ultima)->format('d/m H:i') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-3 text-xs text-slate-500">
                    O bloqueio é automático: 5 erros na mesma conta suspendem o acesso por 5 minutos, e 20 erros
                    vindos do mesmo endereço suspendem por 30 minutos. Não é preciso fazer nada.
                </p>
            </x-cartao>
        @endif

        {{-- Histórico --}}
        <x-cartao titulo="Histórico" descricao="Todas as tentativas, da mais recente para a mais antiga">
            <x-slot:acoes>
                <div class="flex gap-1 rounded-lg bg-slate-100 p-1">
                    @foreach (['' => 'Todas', 'falhas' => 'Sem sucesso', 'sucessos' => 'Entradas'] as $valor => $rotulo)
                        <a
                            href="{{ route('acessos.index', array_filter(['filtro' => $valor])) }}"
                            class="rounded-md px-2.5 py-1 text-xs font-medium transition {{
                                $filtro === $valor ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'
                            }}"
                        >{{ $rotulo }}</a>
                    @endforeach
                </div>
            </x-slot:acoes>

            @if ($tentativas->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">Nenhuma tentativa registrada.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                <th class="py-2 pr-3 font-medium">Quando</th>
                                <th class="py-2 pr-3 font-medium">Usuário tentado</th>
                                <th class="py-2 pr-3 font-medium">Origem</th>
                                <th class="py-2 font-medium">Resultado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($tentativas as $tentativa)
                                <tr class="{{ $tentativa->sucesso ? '' : 'bg-rose-50/40' }}">
                                    <td class="whitespace-nowrap py-2 pr-3 text-slate-600">
                                        {{ $tentativa->created_at?->format('d/m/Y H:i:s') }}
                                    </td>
                                    <td class="py-2 pr-3 font-medium text-slate-900">{{ $tentativa->usuario }}</td>
                                    <td class="py-2 pr-3 font-mono text-xs text-slate-600">
                                        {{ $tentativa->ip }}
                                        @if ($tentativa->ip === $meuIp)
                                            <span class="font-sans text-slate-400">(você)</span>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        <x-badge :cor="$tentativa->motivo->corBadge()" :title="$tentativa->motivo->explicacao()">
                                            {{ $tentativa->motivo->rotulo() }}
                                        </x-badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($tentativas->hasPages())
                    <div class="mt-4">{{ $tentativas->links() }}</div>
                @endif
            @endif
        </x-cartao>

        {{-- Legenda: os termos não são óbvios para quem não é da área --}}
        <x-cartao titulo="O que cada resultado significa">
            <dl class="grid gap-3 sm:grid-cols-2">
                @foreach ($motivos as $motivo)
                    <div class="flex gap-2.5">
                        <dt class="shrink-0">
                            <x-badge :cor="$motivo->corBadge()">{{ $motivo->rotulo() }}</x-badge>
                        </dt>
                        <dd class="text-xs leading-relaxed text-slate-600">{{ $motivo->explicacao() }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-cartao>
    </div>
</x-layouts.app>
