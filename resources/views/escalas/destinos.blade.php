<x-layouts.app
    :titulo="'Destinos · '.$escala->referenciaLonga()"
    subtitulo="Todo motorista ativo precisa ter uma situação definida no mês"
>
    <x-slot:acoes>
        <x-botao href="{{ route('escalas.montar', $escala) }}" variante="secundario" tamanho="pequeno" icone="grade">
            <span class="hidden sm:inline">Montagem</span>
        </x-botao>
    </x-slot:acoes>

    <p class="mb-5 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200">
        Quem não está lotado em uma ambulância precisa ser classificado: <strong>sobreaviso/reserva</strong> para
        cobrir faltas, <strong>apoio</strong> em carro extra, ou <strong>férias, licença e atestado</strong> para
        quem está afastado. Essa classificação é o que faz a lista mensal de ocorrências contemplar todo o efetivo.
    </p>

    @livewire('definir-destinos', ['escala' => $escala])
</x-layouts.app>
