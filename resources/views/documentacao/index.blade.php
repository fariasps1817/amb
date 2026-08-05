<x-layouts.app titulo="Documentação" subtitulo="Guias técnicos do sistema">

    <div class="mx-auto max-w-3xl space-y-5">

        @foreach ($documentos as $doc)
            <x-cartao :titulo="$doc['titulo']" :descricao="$doc['descricao']">
                @if ($doc['existe'])
                    <x-slot:acoes>
                        <x-botao
                            href="{{ route('documentacao.mostrar', $doc['apelido']) }}"
                            target="_blank"
                            tamanho="pequeno"
                            icone="olho"
                        >
                            Abrir
                        </x-botao>
                    </x-slot:acoes>

                    <p class="text-xs text-slate-500">
                        {{ $doc['tamanho'] }} · atualizado em {{ $doc['atualizado'] }}
                    </p>

                    <p class="mt-3 text-sm text-slate-600">
                        Para gerar um PDF, abra o documento e use <strong>Ctrl+P</strong> →
                        <em>Salvar como PDF</em>. Marque <strong>&ldquo;Gráficos de segundo plano&rdquo;</strong>
                        para manter as cores dos títulos e das tabelas.
                    </p>
                @else
                    <p class="text-sm text-amber-700">
                        O arquivo ainda não foi gerado neste servidor. Rode
                        <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">php scripts/gerar-html-do-guia.php</code>
                        e publique novamente.
                    </p>
                @endif
            </x-cartao>
        @endforeach

        <div class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
            <x-icone nome="cadeado" class="mt-0.5 size-5 shrink-0 text-slate-400" />
            <p>
                Esta área é restrita ao administrador e <strong>não é acessível sem login</strong>.
                Os guias descrevem o endereço do servidor, os caminhos dos arquivos e como a
                segurança está montada — por isso não ficam abertos na internet.
            </p>
        </div>
    </div>
</x-layouts.app>
