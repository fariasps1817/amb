@php
    use App\Services\Escalas\ComparadorDePropostas;

    $total = $quadros->count();

    // No celular, um cartão embaixo do outro; no computador, lado a lado.
    $colunas = match (true) {
        $total >= 3 => 'lg:grid-cols-3',
        $total === 2 => 'lg:grid-cols-2',
        default => '',
    };

    // Números que pedem atenção quando passam de zero.
    $alertaSePositivo = ['a_contratar' => 'text-rose-700', 'a_definir' => 'text-rose-700', 'sem_definicao' => 'text-amber-700'];
@endphp

<x-layouts.app
    titulo="Comparar propostas"
    :subtitulo="$escala->referenciaLonga().' · '.$total.' '.($total === 1 ? 'escala' : 'escalas')"
>
    <x-slot:acoes>
        <x-botao href="{{ route('escalas.show', $escala) }}" variante="secundario" tamanho="grande" icone="seta-esquerda">
            Voltar à escala
        </x-botao>
    </x-slot:acoes>

    <div class="space-y-5">

        {{-- ------------------------------------------------------------
             Situação do mês
             ------------------------------------------------------------ --}}

        @if ($oficial)
            <div class="flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <x-icone nome="check" class="mt-0.5 size-5 shrink-0 text-emerald-600" />
                <p>
                    A escala que vale para {{ $escala->referenciaLonga() }} é a
                    <strong>{{ ComparadorDePropostas::nome($oficial) }}</strong>, já {{ mb_strtolower($oficial->status->rotulo()) }}.
                    As outras ficaram guardadas só para consulta.
                </p>
            </div>
        @elseif ($semDecisao->isNotEmpty())
            <div class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <x-icone nome="alerta" class="mt-0.5 size-5 shrink-0 text-amber-600" />
                <p>
                    <strong>{{ $semDecisao->count() }} propostas aguardando decisão.</strong>
                    Depois da escolha, abra a proposta aprovada e publique-a. Até lá, nenhuma delas aparece no plantão do dia nem vai para as unidades.
                </p>
            </div>
        @elseif ($total === 1)
            <div class="flex items-start gap-2.5 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                <x-icone nome="info" class="mt-0.5 size-5 shrink-0 text-slate-400" />
                <p>Este mês tem uma só escala: não há outra proposta para comparar.</p>
            </div>
        @endif

        @if (count($diferentes) > 0)
            <p class="flex items-center gap-2 text-xs text-slate-500">
                <span class="inline-block size-3 shrink-0 rounded-sm bg-amber-100 ring-1 ring-amber-200"></span>
                Em destaque, os números que mudam de uma proposta para outra.
            </p>
        @endif

        {{-- ------------------------------------------------------------
             Uma coluna por proposta
             ------------------------------------------------------------ --}}

        {{-- grid-cols-1 e min-w-0 nos cartões: sem eles, um nome ou uma
             descrição comprida alarga a coluna e a página rola para o lado no
             celular.

             No computador cada cartão ocupa cinco linhas da grade (subgrid):
             nome, descrição, números, pendências e botões. Assim os números
             ficam na mesma altura nos cartões lado a lado, mesmo quando um
             nome ou uma descrição é mais comprido -- é a comparação linha a
             linha que a tela existe para facilitar. --}}
        <div class="grid grid-cols-1 gap-4 {{ $colunas }}">
            @foreach ($quadros as $quadro)
                @php
                    $proposta = $quadro['escala'];
                    $erros = $quadro['erros'];
                @endphp

                <section class="flex min-w-0 flex-col gap-4 rounded-xl bg-white shadow-sm ring-1 lg:row-span-5 lg:grid lg:grid-rows-subgrid {{ $proposta->oficial() ? 'ring-emerald-300' : 'ring-slate-200' }}">
                    <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold break-words {{ $proposta->descartada() ? 'text-slate-500' : 'text-slate-900' }}">
                                {{ $quadro['nome'] }}
                            </h2>
                            @if ($proposta->oficial() && $proposta->aprovada_por)
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Aprovada por {{ $proposta->aprovada_por }}{{ $proposta->aprovada_em ? ' em '.$proposta->aprovada_em->format('d/m/Y') : '' }}
                                </p>
                            @endif
                        </div>
                        <x-badge :cor="$proposta->status->corBadge()" class="shrink-0">{{ $proposta->status->rotulo() }}</x-badge>
                    </header>

                    {{-- A justificativa é o que o coordenador vai ler em voz alta. --}}
                    <div class="px-4">
                        @if (filled($proposta->observacao))
                            <p class="text-sm whitespace-pre-line break-words text-slate-700">{{ $proposta->observacao }}</p>
                        @else
                            <p class="text-sm text-slate-400 italic">Sem descrição.</p>
                        @endif
                    </div>

                    <div class="px-4">
                        <dl class="divide-y divide-slate-100 overflow-hidden rounded-lg ring-1 ring-slate-200">
                            @foreach (ComparadorDePropostas::INDICADORES as $chave => $rotulo)
                                @php
                                    $valor = $quadro['numeros'][$chave];
                                    $cor = is_int($valor) && $valor > 0 && isset($alertaSePositivo[$chave])
                                        ? $alertaSePositivo[$chave]
                                        : 'text-slate-900';
                                @endphp

                                <div class="flex items-baseline justify-between gap-3 px-3 py-2 {{ in_array($chave, $diferentes, true) ? 'bg-amber-50' : 'bg-white' }}">
                                    <dt class="text-sm text-slate-600">{{ $rotulo }}</dt>
                                    <dd class="text-base font-semibold tabular-nums {{ $cor }}">{{ $valor }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    {{-- Pendências: só o número à vista, a lista abre com um toque. --}}
                    <div class="px-4">
                        @if ($erros === [])
                            <p class="flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800 ring-1 ring-emerald-200">
                                <x-icone nome="check" class="size-4 shrink-0" />
                                Nenhuma pendência para publicar.
                            </p>
                        @else
                            <details class="group rounded-lg border border-rose-200 bg-rose-50 text-sm text-rose-800">
                                <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 px-3 py-2 font-medium [&::-webkit-details-marker]:hidden">
                                    <x-icone nome="erro" class="size-4 shrink-0" />
                                    <span class="flex-1">
                                        {{ count($erros) }} {{ count($erros) === 1 ? 'pendência' : 'pendências' }}
                                        {{ match (true) {
                                            $proposta->ehRascunho() => count($erros) === 1 ? 'impede a publicação' : 'impedem a publicação',
                                            $proposta->descartada() => count($erros) === 1 ? 'impediria a publicação' : 'impediriam a publicação',
                                            default => 'na escala',
                                        } }}
                                    </span>
                                    <x-icone nome="seta-baixo" class="size-4 shrink-0 transition group-open:rotate-180" />
                                </summary>
                                <ul class="space-y-1.5 border-t border-rose-200 px-3 py-2.5 text-xs">
                                    @foreach ($erros as $mensagem)
                                        <li class="break-words">{{ $mensagem }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>

                    {{-- mt-auto: os botões ficam no pé do cartão. --}}
                    <div class="mt-auto grid grid-cols-2 gap-2 px-4 pb-4">
                        <x-botao
                            href="{{ route('escalas.show', $proposta) }}"
                            tamanho="grande"
                            icone="olho"
                            aria-label="Abrir {{ $quadro['nome'] }}"
                        >
                            Abrir
                        </x-botao>
                        <x-botao
                            href="{{ route('documentos.planilha', $proposta) }}"
                            target="_blank"
                            variante="secundario"
                            tamanho="grande"
                            icone="impressora"
                        >
                            Planilha
                        </x-botao>
                    </div>
                </section>
            @endforeach
        </div>

        {{-- ------------------------------------------------------------
             O que muda de uma proposta para outra
             ------------------------------------------------------------ --}}

        @if ($total > 1)
            @foreach ([
                [
                    'titulo' => 'Ambulâncias que mudam',
                    'descricao' => 'Ambulâncias que não estão em todas as propostas, ou que mudam de lotação ou de regime.',
                    'linhas' => $ambulancias,
                ],
                [
                    'titulo' => 'Motoristas que mudam de lugar',
                    'descricao' => 'Só quem fica em lugar diferente de uma proposta para outra.',
                    'linhas' => $motoristas,
                ],
            ] as $bloco)
                <x-cartao :titulo="$bloco['titulo']" :descricao="$bloco['descricao']">
                    @if ($bloco['linhas']->isEmpty())
                        <p class="text-sm text-slate-500">As propostas não diferem nisso.</p>
                    @else
                        <ul class="-my-2.5 divide-y divide-slate-100">
                            @foreach ($bloco['linhas'] as $linha)
                                <li class="py-2.5">
                                    <p class="text-sm font-semibold break-words text-slate-900">{{ $linha['rotulo'] }}:</p>
                                    <ul class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach ($linha['situacoes'] as $situacao)
                                            <li class="max-w-full rounded-md px-2 py-1 text-xs break-words ring-1 ring-inset {{ $situacao['ausente'] ? 'bg-slate-50 text-slate-500 ring-slate-200' : 'bg-marca-50 text-marca-900 ring-marca-200' }}">
                                                <span class="font-medium">{{ $situacao['nome'] }}</span> → {{ $situacao['valor'] }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-cartao>
            @endforeach
        @endif
    </div>
</x-layouts.app>
