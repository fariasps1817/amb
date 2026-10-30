@php
    $totalDeFiltros = collect($filtros)->filter(fn ($valor) => filled($valor))->count();
    $porDia = $registros->groupBy(fn ($registro) => $registro->created_at?->toDateString());
@endphp

<x-layouts.app titulo="Auditoria" subtitulo="Quem fez o quê no sistema, quando e de onde">
    <div class="space-y-5">
        @include('auditoria._abas', ['ativa' => 'atividades'])

        <details @if ($totalDeFiltros > 0) open @endif class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <summary class="flex min-h-12 cursor-pointer items-center gap-2 px-4 text-sm font-medium text-slate-700 sm:px-5">
                <x-icone nome="busca" class="size-4 shrink-0 text-slate-500" />
                Filtrar
                @if ($totalDeFiltros > 0)
                    <x-badge>{{ $totalDeFiltros }} {{ $totalDeFiltros === 1 ? 'ativo' : 'ativos' }}</x-badge>
                @endif
            </summary>

            <form method="GET" action="{{ route('auditoria.index') }}" class="grid grid-cols-1 gap-4 border-t border-slate-100 px-4 py-4 sm:grid-cols-2 sm:px-5 lg:grid-cols-3">
                <x-input nome="busca" rotulo="Procurar" :value="$filtros['busca'] ?? ''" placeholder="Nome, protocolo, placa, endereço de rede..." maxlength="100" />
                <x-select nome="usuario" rotulo="Pessoa" vazio="Todas" :opcoes="$pessoas" :selecionado="$filtros['usuario'] ?? null" />
                <x-select nome="categoria" rotulo="Tipo" vazio="Todos" :opcoes="$categorias" :selecionado="$filtros['categoria'] ?? null" />
                <x-input nome="de" tipo="date" rotulo="De" :value="$filtros['de'] ?? ''" />
                <x-input nome="ate" tipo="date" rotulo="Até" :value="$filtros['ate'] ?? ''" />
                <x-select nome="escala" rotulo="Escala" vazio="Todas" :opcoes="$escalas" :selecionado="$filtros['escala'] ?? null" />

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-3">
                    <x-botao type="submit" tamanho="grande" icone="busca">Filtrar</x-botao>
                    @if ($totalDeFiltros > 0)
                        <x-botao href="{{ route('auditoria.index') }}" variante="secundario" tamanho="grande">Limpar</x-botao>
                    @endif
                </div>
            </form>
        </details>

        <x-cartao :titulo="$registros->total().' '.($registros->total() === 1 ? 'registro' : 'registros')" descricao="Do mais recente para o mais antigo">
            <x-slot:acoes>
                {{-- Com ":href": entre chaves, o botão escaparia o endereço duas vezes e só o primeiro filtro valeria. --}}
                <x-botao :href="route('auditoria.exportar', array_filter($filtros))" variante="secundario" tamanho="pequeno" icone="documentos">
                    Exportar planilha
                </x-botao>
            </x-slot:acoes>

            @if ($registros->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">Nenhum registro encontrado.</p>
            @else
                <div class="space-y-5">
                    @foreach ($porDia as $dia => $doDia)
                        <section>
                            <h3 class="border-b border-slate-200 pb-1.5 text-xs font-semibold text-slate-500">
                                {{ ucfirst(\Illuminate\Support\Carbon::parse($dia)->translatedFormat('l, d \d\e F \d\e Y')) }}
                            </h3>
                            <ul class="divide-y divide-slate-100">
                                @foreach ($doDia as $registro)
                                    @include('auditoria._registro', ['registro' => $registro, 'comSessao' => true])
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>

                @if ($registros->hasPages())
                    <div class="mt-4">{{ $registros->links() }}</div>
                @endif
            @endif
        </x-cartao>

        <p class="text-xs leading-relaxed text-slate-500">
            Os registros ficam guardados por {{ $meses }} meses e não podem ser alterados. O dispositivo é uma identificação aproximada.
            Em iPhone com a Retransmissão Privada ligada, o endereço de rede é o da Apple, e não o do aparelho.
            Quem fecha o navegador sem sair só aparece como "sessão expirada" na madrugada seguinte.
        </p>
    </div>
</x-layouts.app>
