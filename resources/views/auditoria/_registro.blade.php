{{-- Uma linha da linha do tempo. Espera $registro; com $comSessao, traz o link para a sessão. --}}
<li class="flex gap-3 py-3">
    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg {{ $registro->categoria->cor() }}" title="{{ $registro->categoria->rotulo() }}">
        <x-icone :nome="$registro->categoria->icone()" class="size-4" />
    </span>

    <div class="min-w-0 flex-1">
        <p class="break-words text-sm text-slate-900">{{ $registro->descricao }}</p>

        <p class="mt-0.5 break-words text-xs text-slate-500">
            <span class="font-medium text-slate-700">{{ $registro->autor() }}</span>
            · {{ $registro->created_at?->format('H:i:s') }}
            · {{ $registro->dispositivo() }}
            @if ($registro->ip)
                · {{ $registro->ip }}
            @endif
        </p>

        @if (($comSessao ?? false) && $registro->sessao)
            <a href="{{ route('auditoria.sessao', $registro->sessao) }}" class="mt-0.5 inline-block py-1 text-xs font-medium text-marca-700 hover:underline">Ver a sessão inteira</a>
        @endif

        @if ($registro->alteracoes)
            <details class="mt-0.5">
                <summary class="cursor-pointer py-1 text-xs font-medium text-marca-700">Ver detalhes</summary>

                <dl class="mt-1 divide-y divide-slate-100 rounded-lg bg-slate-50 px-3 text-xs ring-1 ring-inset ring-slate-200">
                    @foreach ($registro->alteracoes as $alteracao)
                        <div class="py-2">
                            <dt class="font-medium text-slate-700">{{ $alteracao['campo'] }}</dt>
                            <dd class="mt-0.5 break-words text-slate-600">
                                @if (filled($alteracao['de']))
                                    <p><span class="text-slate-500">Antes:</span> <span class="whitespace-pre-line">{{ $alteracao['de'] }}</span></p>
                                    <p><span class="text-slate-500">Depois:</span> <span class="whitespace-pre-line">{{ $alteracao['para'] ?? '(vazio)' }}</span></p>
                                @else
                                    <p class="whitespace-pre-line">{{ $alteracao['para'] }}</p>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </details>
        @endif
    </div>
</li>
