<x-layouts.app
    :titulo="'Destinos · '.$escala->referenciaLonga()"
    subtitulo="Todo motorista ativo precisa ter uma situação definida no mês"
>
    <x-slot:acoes>
        <x-botao href="{{ route('escalas.montar', $escala) }}" variante="secundario" tamanho="pequeno" icone="grade">
            <span class="hidden sm:inline">Montagem</span>
        </x-botao>
    </x-slot:acoes>

    <div class="mb-5 space-y-2">
        <p class="rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200">
            Quem não está lotado em uma ambulância precisa ser classificado: <strong>sobreaviso/reserva</strong> para
            cobrir faltas, <strong>apoio</strong> em carro extra, ou <strong>férias, licença e atestado</strong> para
            quem está afastado. Essa classificação é o que faz a lista mensal de ocorrências contemplar todo o efetivo.
        </p>

        <p class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700 ring-1 ring-slate-200">
            É aqui também que se registra a <strong>ocorrência de quem está escalado</strong> — uma falta, um
            atestado, uma troca de plantão. Use <strong>Registrar ocorrência</strong> na linha do motorista: o texto
            vai para a coluna OCORRÊNCIA da lista mensal, e ele continua escalado normalmente.
        </p>
    </div>

    @livewire('definir-destinos', ['escala' => $escala])
</x-layouts.app>
