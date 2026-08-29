<!DOCTYPE html>
<html lang="pt-BR" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2b736e">

    <title>{{ isset($titulo) ? "{$titulo} · " : '' }}{{ config('app.name') }}</title>

    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#2b736e"/><path d="M16 7v18M7 16h18" stroke="#fff" stroke-width="4.5" stroke-linecap="round"/></svg>') }}">

    {{-- A preferencia de barra lateral recolhida precisa valer ANTES da
         primeira pintura. Lida depois, no app.js, a barra apareceria larga e
         encolheria na frente do usuario a cada troca de pagina. --}}
    <script>
        try {
            if (localStorage.getItem('menu-recolhido') === '1') {
                document.documentElement.classList.add('menu-recolhido');
            }
        } catch (e) {
            // Navegador com armazenamento bloqueado: segue com a barra inteira.
        }
    </script>

    {{-- Carrega a Instrument Sans a partir dos arquivos do proprio servidor.
         Sem esta linha o Vite gera as fontes, mas nada as referencia, e o
         sistema cai na fonte padrao do sistema operacional. --}}
    {{ Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans text-slate-900 antialiased">
<div x-data="{ menuAberto: false }" class="min-h-full">

    {{-- ----------------------------------------------------------------
         Menu lateral em telas grandes / gaveta no celular
         ---------------------------------------------------------------- --}}

    {{-- Fundo escuro da gaveta --}}
    <div
        x-show="menuAberto"
        x-transition.opacity
        @click="menuAberto = false"
        class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden nao-imprimir"
        aria-hidden="true"
        style="display: none"
    ></div>

    <aside
        class="barra-lateral fixed inset-y-0 left-0 z-50 flex w-72 flex-col overflow-hidden bg-marca-900 transition-[transform,width] duration-200 lg:translate-x-0 nao-imprimir"
        :class="menuAberto ? 'translate-x-0' : '-translate-x-full'"
        aria-label="Menu principal"
    >
        <div class="menu-topo flex h-16 items-center justify-between gap-2 px-5">
            <a href="{{ route('painel') }}" class="menu-marca flex min-w-0 items-center gap-2.5" title="Ir para o painel">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15">
                    <x-icone nome="ambulancias" class="size-5 text-white" />
                </span>
                <span class="menu-texto min-w-0">
                    <span class="block truncate text-sm font-semibold text-white">Ambulâncias</span>
                    <span class="block truncate text-xs text-marca-200">Coordenação</span>
                </span>
            </a>

            <button
                type="button"
                @click="menuAberto = false"
                class="rounded-lg p-1.5 text-marca-200 hover:bg-white/10 hover:text-white lg:hidden"
            >
                <x-icone nome="fechar" class="size-5" />
                <span class="sr-only">Fechar menu</span>
            </button>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-4 scrollbar-fina">
            <x-nav-grupo titulo="Operação">
                <x-nav-item rota="painel" icone="painel">Painel</x-nav-item>
                <x-nav-item rota="escalas.index" icone="escalas" :ativo="request()->routeIs('escalas.*')">
                    Escalas mensais
                </x-nav-item>
            </x-nav-grupo>

            <x-nav-grupo titulo="Cadastros">
                <x-nav-item rota="motoristas.index" icone="motoristas" :ativo="request()->routeIs('motoristas.*')">
                    Motoristas
                </x-nav-item>
                <x-nav-item rota="unidades.index" icone="unidades" :ativo="request()->routeIs('unidades.*')">
                    Unidades
                </x-nav-item>
                <x-nav-item rota="ambulancias.index" icone="ambulancias" :ativo="request()->routeIs('ambulancias.*')">
                    Ambulâncias
                </x-nav-item>
            </x-nav-grupo>

            <x-nav-grupo titulo="Sistema">
                @if (auth()->user()->ehAdmin())
                    <x-nav-item rota="usuarios.index" icone="usuarios" :ativo="request()->routeIs('usuarios.*')">
                        Usuários
                    </x-nav-item>
                    <x-nav-item rota="acessos.index" icone="cadeado" :ativo="request()->routeIs('acessos.*')">
                        Acessos
                    </x-nav-item>
                    <x-nav-item rota="documentacao.index" icone="documentos" :ativo="request()->routeIs('documentacao.*')">
                        Documentação
                    </x-nav-item>
                @endif
                <x-nav-item rota="configuracoes.edit" icone="configuracoes" :ativo="request()->routeIs('configuracoes.*')">
                    Identidade institucional
                </x-nav-item>
            </x-nav-grupo>
        </nav>

        <div class="menu-rodape border-t border-white/10 p-3">
            <div class="menu-rodape-linha flex items-center gap-3 rounded-lg px-2 py-2">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-white ring-1 ring-white/15">
                    {{ auth()->user()->iniciais() }}
                </span>
                <span class="menu-texto min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-white">{{ auth()->user()->nome }}</span>
                    <span class="block truncate text-xs text-marca-200">{{ auth()->user()->perfilRotulo() }}</span>
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="rounded-lg p-1.5 text-marca-200 transition hover:bg-white/10 hover:text-white"
                        title="Sair do sistema"
                    >
                        <x-icone nome="sair" class="size-5" />
                        <span class="sr-only">Sair</span>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ----------------------------------------------------------------
         Conteúdo
         ---------------------------------------------------------------- --}}

    <div class="area-conteudo transition-[padding] duration-200 lg:pl-72">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 nao-imprimir">
            <button
                type="button"
                @click="menuAberto = true"
                class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
            >
                <x-icone nome="menu" class="size-6" />
                <span class="sr-only">Abrir menu</span>
            </button>

            {{-- So no computador: no celular a barra ja e uma gaveta. --}}
            <button
                type="button"
                data-recolher-menu
                class="-ml-1 hidden rounded-lg p-2 text-slate-600 transition hover:bg-slate-100 lg:inline-flex"
                title="Recolher ou expandir o menu"
            >
                <x-icone nome="barra-lateral" class="size-6" />
                <span class="sr-only">Recolher ou expandir o menu</span>
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold text-slate-900 sm:text-lg">
                    {{ $titulo ?? 'Painel' }}
                </h1>
                @isset($subtitulo)
                    <p class="truncate text-xs text-slate-500">{{ $subtitulo }}</p>
                @endisset
            </div>

            @isset($acoes)
                <div class="flex shrink-0 items-center gap-2">{{ $acoes }}</div>
            @endisset
        </header>

        <main class="px-4 py-5 sm:px-6 sm:py-6">
            <x-avisos />
            {{ $slot }}
        </main>
    </div>

    <x-toast />
    <x-aviso-de-inatividade />
</div>

{{-- Antes do @livewireScripts: e ele que carrega o Alpine, e o aviso de
     inatividade precisa se registrar no evento alpine:init. --}}
@stack('scripts')
@livewireScripts
</body>
</html>
