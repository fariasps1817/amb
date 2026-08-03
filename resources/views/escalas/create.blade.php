@php
    $meses = collect(range(1, 12))->mapWithKeys(fn ($m) => [
        $m => ucfirst(\Illuminate\Support\Carbon::create(null, $m, 1)->translatedFormat('F')),
    ])->all();

    $anos = collect(range(now()->year - 1, now()->year + 2))->mapWithKeys(fn ($a) => [$a => $a])->all();
    $saldo = $motoristasAtivos - $demandaEstimada;
@endphp

<x-layouts.app titulo="Nova escala mensal">
    <form method="POST" action="{{ route('escalas.store') }}" class="mx-auto max-w-2xl space-y-5">
        @csrf

        {{-- Dimensionamento antes de começar: evita montar o mês e só então
             descobrir que o efetivo não fecha. --}}
        <x-cartao titulo="Situação atual do setor">
            <dl class="grid grid-cols-3 gap-4">
                <div>
                    <dt class="text-xs text-slate-500">Ambulâncias com lotação</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-slate-900 tabular-nums">{{ $frotaAtiva }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Motoristas exigidos</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-slate-900 tabular-nums">{{ $demandaEstimada }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Motoristas ativos</dt>
                    <dd class="mt-0.5 text-2xl font-semibold text-slate-900 tabular-nums">{{ $motoristasAtivos }}</dd>
                </div>
            </dl>

            @if ($frotaAtiva === 0)
                <p class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <x-icone nome="alerta" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        Nenhuma ambulância ativa está vinculada a uma unidade. A escala será criada vazia e você
                        adiciona os postos manualmente.
                        <a href="{{ route('ambulancias.index') }}" class="font-medium underline">Revisar frota</a>.
                    </span>
                </p>
            @elseif ($saldo < 0)
                <p class="mt-3 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <x-icone nome="erro" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        Faltam <strong>{{ abs($saldo) }} motorista(s)</strong> para cobrir todas as ambulâncias nos
                        regimes cadastrados. Você pode montar a escala, mas alguns dias ficarão sem cobertura.
                    </span>
                </p>
            @else
                <p class="mt-3 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    <x-icone nome="check" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        O efetivo cobre a frota, com <strong>{{ $saldo }}</strong> motorista(s) sobrando para
                        reserva, sobreaviso, férias e licenças.
                    </span>
                </p>
            @endif
        </x-cartao>

        <x-cartao titulo="Mês de referência">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-select
                    rotulo="Mês"
                    name="mes"
                    :opcoes="$meses"
                    :selecionado="old('mes', $sugestao->month)"
                    required
                    obrigatorio
                />
                <x-select
                    rotulo="Ano"
                    name="ano"
                    :opcoes="$anos"
                    :selecionado="old('ano', $sugestao->year)"
                    required
                    obrigatorio
                />
            </div>

            @if ($ultimaEscala)
                <div class="mt-4 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
                    <label class="flex items-start gap-2.5 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            name="copiar_mes_anterior"
                            value="1"
                            class="mt-0.5 size-4 rounded border-slate-300 text-marca-600 focus:ring-marca-600"
                            @checked(old('copiar_mes_anterior', true))
                        >
                        <span>
                            <strong>Repetir a estrutura do mês anterior</strong>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                Copia as ambulâncias, as unidades, os regimes e as equipes, além dos motoristas em
                                reserva ou apoio. Quem estiver de férias ou licença não é copiado, e quem tiver
                                contrato encerrado ou CNH vencida no novo mês deixa a vaga aberta.
                                A rotação continua de onde parou.
                            </span>
                        </span>
                    </label>
                </div>
            @else
                <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-inset ring-slate-200">
                    Esta é a primeira escala do sistema. Os postos serão montados a partir das ambulâncias ativas
                    que já têm unidade de lotação, cada uma com o regime da sua unidade.
                </p>
            @endif
        </x-cartao>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <x-botao href="{{ route('escalas.index') }}" variante="secundario">Cancelar</x-botao>
            <x-botao type="submit" icone="mais">Criar e montar escala</x-botao>
        </div>
    </form>
</x-layouts.app>
