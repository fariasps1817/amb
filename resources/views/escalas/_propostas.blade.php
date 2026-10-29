{{--
    Propostas do mês: alternância entre as alternativas, nome e descrição,
    aviso de proposta descartada e o registro da aprovação da publicada.
--}}
@php
    $editor = auth()->user()->podeEditar();
    $temAlternativas = $doMes->count() > 1 || filled($escala->nome);
@endphp

@if ($escala->descartada())
    <div class="flex flex-wrap items-start gap-3 rounded-xl border border-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-700">
        <x-icone nome="info" class="mt-0.5 size-5 shrink-0 text-slate-500" />
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-slate-800">Esta proposta foi descartada.</p>
            <p class="mt-0.5">
                Outra proposta foi publicada para {{ mb_strtolower($escala->referenciaLonga()) }}. Esta fica guardada só para consulta:
                não aparece no painel, no plantão do dia nem nas mensagens.
            </p>
        </div>

        @if ($editor)
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                @unless ($mesTemOficial)
                    <form method="POST" action="{{ route('escalas.restaurar', $escala) }}">
                        @csrf
                        <x-botao type="submit" variante="secundario" tamanho="pequeno" icone="atualizar">Voltar para rascunho</x-botao>
                    </form>
                @endunless
                <form
                    method="POST"
                    action="{{ route('escalas.destroy', $escala) }}"
                    onsubmit="return confirm('Excluir de vez a {{ $escala->nome ?? 'proposta' }}? Os postos e plantões dela serão apagados.')"
                >
                    @csrf
                    @method('DELETE')
                    <x-botao type="submit" variante="perigo" tamanho="pequeno" icone="lixeira">Excluir</x-botao>
                </form>
            </div>
        @endif
    </div>
@endif

@if ($temAlternativas)
    <x-cartao
        :titulo="'Propostas de '.mb_strtolower($escala->referenciaLonga())"
        :descricao="$mesTemOficial
            ? 'A escala publicada é a que vale; as outras ficam só para consulta.'
            : 'Enquanto nenhuma for publicada, nenhuma delas aparece no painel, no plantão do dia nem nas mensagens.'"
    >
        @if ($doMes->count() > 1)
            <x-slot:acoes>
                <x-botao href="{{ route('escalas.comparar', $escala) }}" variante="suave" tamanho="pequeno" icone="grade">
                    Comparar propostas
                </x-botao>
            </x-slot:acoes>
        @endif

        {{-- Alternar entre as propostas do mês --}}
        <nav class="flex flex-wrap gap-2" aria-label="Propostas do mês">
            @foreach ($doMes as $proposta)
                @php $atual = $proposta->is($escala); @endphp
                <a
                    href="{{ route('escalas.show', $proposta) }}"
                    @if ($atual) aria-current="page" @endif
                    class="flex min-h-10 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset transition {{
                        $atual ? 'bg-marca-50 text-marca-900 ring-marca-300' : 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50'
                    }}"
                >
                    {{ $proposta->nome ?? 'Escala do mês' }}
                    <x-badge :cor="$proposta->status->corBadge()">{{ $proposta->status->rotulo() }}</x-badge>
                </a>
            @endforeach
        </nav>

        {{-- Descrição: a justificativa que vai para quem decide --}}
        <div class="mt-4" x-data="{ editando: {{ $errors->has('nome') ? 'true' : 'false' }} }">
            <div x-show="! editando">
                @if (filled($escala->observacao))
                    <p class="whitespace-pre-line text-sm text-slate-700">{{ $escala->observacao }}</p>
                @else
                    <p class="text-sm text-slate-400">Sem descrição. Conte o que esta proposta tem de diferente — por exemplo, "depende da contratação de 2 motoristas".</p>
                @endif

                @if ($editor && ! $escala->oficial() && ! $escala->descartada())
                    <button type="button" x-on:click="editando = true" class="mt-2 text-sm font-medium text-marca-700 hover:underline">
                        Editar nome e descrição
                    </button>
                @endif
            </div>

            @if ($editor && ! $escala->oficial() && ! $escala->descartada())
                <form method="POST" action="{{ route('escalas.renomear', $escala) }}" x-show="editando" x-cloak class="space-y-3">
                    @csrf
                    @method('PUT')
                    <x-input nome="nome" rotulo="Nome da proposta" :value="old('nome', $escala->nome)" placeholder="Ex.: Com as 2 contratações" maxlength="60" />
                    <x-textarea nome="observacao" rotulo="Descrição" linhas="3" placeholder="O que muda nesta proposta e do que ela depende">{{ old('observacao', $escala->observacao) }}</x-textarea>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row">
                        <x-botao x-on:click="editando = false" variante="secundario">Cancelar</x-botao>
                        <x-botao type="submit" icone="check">Salvar</x-botao>
                    </div>
                </form>
            @endif
        </div>
    </x-cartao>
@endif

{{-- Quem aprovou e quem publicou: o registro que responde "quem decidiu isto" --}}
@if ($escala->oficial() && ($escala->publicada_em || $escala->aprovada_por))
    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-900 ring-1 ring-inset ring-emerald-200">
        <x-icone nome="check" class="size-4 shrink-0" />
        @if ($escala->aprovada_por)
            <span>Aprovada por <strong>{{ $escala->aprovada_por }}</strong>@if ($escala->aprovada_em) em {{ $escala->aprovada_em->format('d/m/Y') }}@endif.</span>
        @endif
        @if ($escala->publicada_em)
            <span>Publicada em {{ $escala->publicada_em->format('d/m/Y H:i') }}@if ($escala->publicador) por {{ $escala->publicador->nome }}@endif.</span>
        @endif
    </p>
@endif
