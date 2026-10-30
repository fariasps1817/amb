@php
    use App\Enums\AcaoAuditada;
@endphp

<x-layouts.app titulo="Auditoria" subtitulo="Cada entrada no sistema, do início ao fim">
    <div class="space-y-5">
        @include('auditoria._abas', ['ativa' => 'sessoes'])

        <x-cartao>
            <form method="GET" action="{{ route('auditoria.sessoes') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-select nome="usuario" rotulo="Pessoa" vazio="Todas" :opcoes="$pessoas" :selecionado="$filtros['usuario'] ?? null" />
                <x-input nome="de" tipo="date" rotulo="De" :value="$filtros['de'] ?? ''" />
                <x-input nome="ate" tipo="date" rotulo="Até" :value="$filtros['ate'] ?? ''" />
                <x-botao type="submit" tamanho="grande" icone="busca">Filtrar</x-botao>
            </form>
        </x-cartao>

        <x-cartao titulo="Sessões" descricao="Da mais recente para a mais antiga">
            @if ($sessoes->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">Nenhuma sessão encontrada.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($sessoes as $sessao)
                        @php
                            $primeiro = $primeiros[$sessao->primeiro_id] ?? null;
                            $encerramento = $sessao->encerramento ? AcaoAuditada::tryFrom($sessao->encerramento) : null;
                        @endphp
                        <li>
                            <a href="{{ route('auditoria.sessao', $sessao->sessao) }}" class="-mx-2 block rounded-lg px-2 py-3 transition hover:bg-slate-50">
                                <span class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <span class="text-sm font-medium text-slate-900">{{ $primeiro?->autor() }}</span>
                                    <x-badge :cor="$encerramento ? 'bg-slate-100 text-slate-700 ring-slate-200' : 'bg-emerald-100 text-emerald-800 ring-emerald-200'">
                                        {{ $encerramento?->rotuloDeEncerramento() ?? 'Aberta' }}
                                    </x-badge>
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-600">
                                    {{ $sessao->inicio->format('d/m/Y H:i') }} até {{ $sessao->fim->format($sessao->inicio->isSameDay($sessao->fim) ? 'H:i' : 'd/m/Y H:i') }}
                                    · {{ (int) $sessao->acoes }} {{ (int) $sessao->acoes === 1 ? 'ação' : 'ações' }}
                                </span>
                                <span class="mt-0.5 block break-words text-xs text-slate-500">
                                    {{ $primeiro?->dispositivo() }}@if ($primeiro?->ip) · {{ $primeiro->ip }}@endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($sessoes->hasPages())
                    <div class="mt-4">{{ $sessoes->links() }}</div>
                @endif
            @endif
        </x-cartao>

        <p class="text-xs leading-relaxed text-slate-500">
            "Aberta" quer dizer que ainda não houve saída registrada: a pessoa pode continuar usando, ou ter fechado o navegador.
            Nesse caso, a sessão aparece como expirada depois da limpeza da madrugada.
        </p>
    </div>
</x-layouts.app>
