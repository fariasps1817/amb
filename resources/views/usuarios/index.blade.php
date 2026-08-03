<x-layouts.app titulo="Usuários" subtitulo="Quem tem acesso ao sistema">
    <x-slot:acoes>
        <x-botao href="{{ route('usuarios.create') }}" icone="mais" tamanho="pequeno">
            <span class="hidden sm:inline">Novo usuário</span>
            <span class="sm:hidden">Novo</span>
        </x-botao>
    </x-slot:acoes>

    <div class="space-y-4">
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <ul class="divide-y divide-slate-100">
                @foreach ($usuarios as $usuario)
                    <li class="flex flex-wrap items-center gap-3 p-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-marca-50 text-xs font-semibold text-marca-700 ring-1 ring-marca-100">
                            {{ $usuario->iniciais() }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-900">
                                {{ $usuario->nome }}
                                @if ($usuario->is(auth()->user()))
                                    <span class="text-xs font-normal text-slate-500">(você)</span>
                                @endif
                            </p>
                            <p class="truncate text-xs text-slate-500">
                                {{ $usuario->usuario }}
                                @if ($usuario->ultimo_acesso_em)
                                    · último acesso {{ $usuario->ultimo_acesso_em->format('d/m/Y H:i') }}
                                @else
                                    · nunca acessou
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <x-badge :cor="$usuario->ehAdmin() ? 'bg-indigo-100 text-indigo-800 ring-indigo-200' : 'bg-slate-100 text-slate-700 ring-slate-200'">
                                {{ $usuario->perfilRotulo() }}
                            </x-badge>
                            @unless ($usuario->ativo)
                                <x-badge cor="bg-slate-100 text-slate-600 ring-slate-200">inativo</x-badge>
                            @endunless
                            <x-botao href="{{ route('usuarios.edit', $usuario) }}" variante="texto" tamanho="pequeno" icone="lapis">
                                <span class="sr-only">Editar {{ $usuario->nome }}</span>
                            </x-botao>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($usuarios->hasPages())
            <div>{{ $usuarios->links() }}</div>
        @endif
    </div>
</x-layouts.app>
