@php $config = \App\Models\Configuracao::atual(); @endphp

<x-layouts.visitante titulo="Entrar">
    <div class="mx-auto w-full max-w-sm">

        {{-- Identidade institucional, quando cadastrada --}}
        <div class="mb-6 text-center">
            @if ($logo = $config->urlImagem('logo_prefeitura') ?? $config->urlImagem('brasao'))
                <img src="{{ $logo }}" alt="{{ $config->prefeitura }}" class="mx-auto mb-3 h-16 w-auto object-contain">
            @else
                <span class="mx-auto mb-3 flex size-14 items-center justify-center rounded-xl bg-marca-600 shadow-sm">
                    <x-icone nome="ambulancias" class="size-7 text-white" />
                </span>
            @endif

            <h1 class="text-lg font-semibold text-slate-900">
                {{ $config->secretaria ?: 'Secretaria Municipal de Saúde' }}
            </h1>
            <p class="mt-0.5 text-sm text-slate-600">
                {{ $config->setor ?: 'Coordenação de Ambulâncias' }}
            </p>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            @if (session('sucesso'))
                <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {{ session('sucesso') }}
                </p>
            @endif

            {{-- Sessao encerrada por inatividade: sem esta mensagem o usuario
                 volta para o login sem entender por que foi desconectado. --}}
            @if (session('atencao'))
                <div
                    role="status"
                    class="mb-4 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"
                >
                    <x-icone nome="relogio" class="mt-0.5 size-4 shrink-0" />
                    <p>{{ session('atencao') }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <x-input
                    rotulo="Usuário"
                    name="usuario"
                    value="{{ old('usuario') }}"
                    autocomplete="username"
                    autofocus
                    required
                    obrigatorio
                    placeholder="seu.usuario"
                />

                <x-input
                    rotulo="Senha"
                    name="password"
                    tipo="password"
                    autocomplete="current-password"
                    required
                    obrigatorio
                    inputmode="numeric"
                    placeholder="••••"
                />

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        type="checkbox"
                        name="lembrar"
                        value="1"
                        class="size-4 rounded border-slate-300 text-marca-600 focus:ring-marca-600"
                        @checked(old('lembrar'))
                    >
                    Manter a sessão neste computador
                </label>

                <x-botao type="submit" class="w-full" tamanho="grande">
                    Entrar
                </x-botao>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-slate-500">
            {{ $config->prefeitura ?: config('app.name') }}
            @if ($config->municipio)
                · {{ $config->municipio }}
            @endif
        </p>
    </div>
</x-layouts.visitante>
