{{-- Navegação entre as telas da auditoria. Espera $ativa: atividades, sessoes ou tentativas. --}}
<nav class="flex gap-1 overflow-x-auto rounded-lg bg-slate-100 p-1" aria-label="Telas da auditoria">
    @foreach (['atividades' => ['O que foi feito', route('auditoria.index')], 'sessoes' => ['Sessões', route('auditoria.sessoes')], 'tentativas' => ['Tentativas', route('acessos.index')]] as $aba => [$rotulo, $endereco])
        <a
            href="{{ $endereco }}"
            @if ($ativa === $aba) aria-current="page" @endif
            class="flex min-h-10 flex-1 items-center justify-center whitespace-nowrap rounded-md px-2 text-sm font-medium transition sm:px-3 {{
                $ativa === $aba ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'
            }}"
        >{{ $rotulo }}</a>
    @endforeach
</nav>
