<x-layouts.app
    :titulo="'Destinos · '.$escala->referenciaLonga()"
    subtitulo="Todo motorista ativo precisa ter uma situação definida no mês"
>
    <x-slot:acoes>
        <x-botao href="{{ route('escalas.montar', $escala) }}" variante="secundario" tamanho="pequeno" icone="grade">
            Montagem
        </x-botao>
    </x-slot:acoes>

    {{-- Recolhida no celular, onde as explicações empurravam a lista para a
         segunda tela; no computador abre sozinha. --}}
    <details
        class="mb-5 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200"
        x-data
        x-init="if (window.matchMedia('(min-width: 640px)').matches) $el.open = true"
    >
        <summary class="cursor-pointer font-medium">Como usar esta tela</summary>

        <div class="mt-2 space-y-2 text-sky-800">
            <p>
                Quem não está lotado em uma ambulância precisa ser classificado: <strong>sobreaviso/reserva</strong> para
                cobrir faltas, <strong>apoio</strong> em carro extra, ou <strong>férias, licença e atestado</strong> para
                quem está afastado. Essa classificação é o que faz a lista mensal de ocorrências contemplar todo o efetivo.
            </p>
            <p>
                É aqui também que se registra a <strong>ocorrência de quem está escalado</strong> — uma falta, um
                atestado, uma troca de plantão. Use <strong>Registrar ocorrência</strong> na linha do motorista: o texto
                vai para a coluna OCORRÊNCIA da lista mensal, e ele continua escalado normalmente.
            </p>
        </div>
    </details>

    @livewire('definir-destinos', ['escala' => $escala])
</x-layouts.app>
