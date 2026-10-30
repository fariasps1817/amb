<x-layouts.app titulo="Sessão de {{ $primeiro->autor() }}" subtitulo="Tudo o que aconteceu nesta entrada no sistema">
    <x-slot:acoes>
        <x-botao href="{{ route('auditoria.sessoes') }}" variante="secundario" tamanho="pequeno" icone="seta-esquerda">
            Voltar às sessões
        </x-botao>
    </x-slot:acoes>

    <div class="mx-auto max-w-4xl space-y-5">
        <x-cartao>
            <dl class="grid gap-4 sm:grid-cols-2">
                <x-campo-leitura rotulo="Pessoa" :valor="$primeiro->autor()" />
                <x-campo-leitura rotulo="Situação" :valor="$encerramento ?? 'Aberta'" />
                <x-campo-leitura rotulo="Início" :valor="$primeiro->created_at?->format('d/m/Y \à\s H:i')" />
                <x-campo-leitura rotulo="Último registro" :valor="$registros->last()->created_at?->format('d/m/Y \à\s H:i')" />
                <x-campo-leitura rotulo="Dispositivo" :valor="$primeiro->dispositivo()" />
                <x-campo-leitura rotulo="Endereço de rede" :valor="$primeiro->ip" />
            </dl>
        </x-cartao>

        <x-cartao :titulo="$registros->count().' '.($registros->count() === 1 ? 'registro' : 'registros')" descricao="Na ordem em que aconteceram">
            <ul class="divide-y divide-slate-100">
                @foreach ($registros as $registro)
                    @include('auditoria._registro', ['registro' => $registro])
                @endforeach
            </ul>
        </x-cartao>
    </div>
</x-layouts.app>
