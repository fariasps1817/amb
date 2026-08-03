<x-layouts.app
    :titulo="'Montar escala · '.$escala->referenciaLonga()"
    subtitulo="Defina as ambulâncias do mês e a equipe de cada uma"
>
    <x-slot:acoes>
        <x-botao href="{{ route('escalas.show', $escala) }}" variante="secundario" tamanho="pequeno" icone="olho">
            <span class="hidden sm:inline">Ver planilha</span>
        </x-botao>
    </x-slot:acoes>

    {{-- Explicação do modelo de rotação, visível na primeira montagem --}}
    <details class="mb-5 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200" @if (! $escala->gerada()) open @endif>
        <summary class="cursor-pointer font-medium">Como a fila de plantões funciona</summary>
        <div class="mt-2 space-y-1.5 text-sky-800">
            <p>
                Cada ambulância abre a quantidade de vagas que o regime da unidade exige:
                <strong>24/72 usa 4 motoristas</strong> e <strong>24/48 usa 3</strong>.
            </p>
            <p>
                As posições giram um dia para cada motorista. Em 24/72, a posição 1 pega o dia 1º,
                a 2 pega o dia 2, a 3 o dia 3, a 4 o dia 4 — e no dia 5 volta a posição 1.
            </p>
            <p>
                Com a <strong>rotação contínua</strong> ativada, a fila retoma de onde parou no mês anterior,
                para nenhum motorista trabalhar em dias seguidos na virada do mês.
            </p>
        </div>
    </details>

    @livewire('montar-escala', ['escala' => $escala])
</x-layouts.app>
