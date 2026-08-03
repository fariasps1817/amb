@php
    $semPlantoes = $totalPlantoes === 0;
    $config = \App\Models\Configuracao::atual();
@endphp

<x-layouts.app
    :titulo="'Documentos · '.$escala->referenciaLonga()"
    subtitulo="Emissão dos documentos oficiais da escala"
>
    <x-slot:acoes>
        <x-badge :cor="$escala->status->corBadge()">{{ $escala->status->rotulo() }}</x-badge>
        <x-botao href="{{ route('escalas.show', $escala) }}" variante="secundario" tamanho="pequeno" icone="seta-esquerda">
            <span class="hidden sm:inline">Voltar</span>
        </x-botao>
    </x-slot:acoes>

    <div class="mx-auto max-w-4xl space-y-5">

        @if ($semPlantoes)
            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <x-icone nome="alerta" class="mt-0.5 size-5 shrink-0" />
                <p>
                    Os plantões desta escala ainda não foram gerados, então os documentos sairão vazios.
                    @if ($escala->editavel() && auth()->user()->podeEditar())
                        <a href="{{ route('escalas.show', $escala) }}" class="font-medium underline">Gerar agora</a>.
                    @endif
                </p>
            </div>
        @elseif ($escala->ehRascunho())
            <div class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                <x-icone nome="info" class="mt-0.5 size-5 shrink-0" />
                <p>
                    Esta escala está em <strong>rascunho</strong>. Você pode emitir os documentos para conferência,
                    mas publique-a antes de distribuir às unidades.
                </p>
            </div>
        @endif

        {{-- Documento 1 --}}
        <x-documento-cartao
            titulo="Planilha de plantões"
            descricao="Calendário do mês com um X no dia de plantão de cada motorista. É o documento distribuído às unidades para acompanharem quem está de plantão."
            icone="grade"
            :detalhes="[
                $escala->postos->count().' ambulância(s)',
                $totalEscalados.' motorista(s)',
                $escala->diasNoMes().' dias',
                'A4 paisagem',
            ]"
            :ver="route('documentos.planilha', $escala)"
            :baixar="route('documentos.planilha', [$escala, 'download' => 1])"
        />

        {{-- Documento 2 --}}
        <x-documento-cartao
            titulo="Lista mensal de ocorrências"
            descricao="Relação de todo o efetivo em ordem alfabética, com lotação, vínculo, plantões previstos e observações — incluindo reservas, férias e licenças."
            icone="documentos"
            :detalhes="[
                $totalLinhasOcorrencias.' servidor(es)',
                'ordem alfabética',
                'A4 retrato',
            ]"
            :ver="route('documentos.ocorrencias', $escala)"
            :baixar="route('documentos.ocorrencias', [$escala, 'download' => 1])"
        />

        {{-- Documento 3 --}}
        <x-documento-cartao
            titulo="Folhas de frequência"
            descricao="Uma folha por motorista, com todos os dias do mês e espaço para assinatura nos dias de plantão. Os dias de descanso saem marcados como folga."
            icone="impressora"
            :detalhes="[
                $totalEscalados.' folha(s)',
                'uma por página',
                'A4 retrato',
            ]"
            :ver="route('documentos.frequencias', $escala)"
            :baixar="route('documentos.frequencias', [$escala, 'download' => 1])"
        />

        {{-- Reemissão individual: útil quando um motorista perde a folha dele --}}
        @if ($escalados->isNotEmpty())
            <x-cartao
                titulo="Folha de frequência individual"
                descricao="Reemitir a folha de um motorista específico"
            >
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($escalados as $lotacao)
                        <a
                            href="{{ route('documentos.frequencia', [$escala, $lotacao->motorista]) }}"
                            target="_blank"
                            class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 transition hover:bg-slate-100"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm text-slate-800">{{ $lotacao->motorista->nome_curto }}</span>
                                <span class="block truncate text-xs text-slate-500">
                                    {{ $lotacao->rotuloLotacao() }} · {{ $lotacao->plantoes_previstos }} plantão(ões)
                                </span>
                            </span>
                            <x-icone nome="impressora" class="size-4 shrink-0 text-slate-400" />
                        </a>
                    @endforeach
                </div>
            </x-cartao>
        @endif

        <x-cartao
            titulo="Comunicação aos motoristas"
            descricao="Mensagem individual de WhatsApp com os dias de plantão do mês"
        >
            <x-slot:acoes>
                <x-botao href="{{ route('mensagens.index', $escala) }}" tamanho="pequeno" icone="whatsapp">
                    Abrir mensagens
                </x-botao>
            </x-slot:acoes>

            <p class="text-sm text-slate-600">
                O sistema monta o texto de cada motorista listando as datas em que ele está escalado, a unidade e a
                ambulância. Você confere e envia.
            </p>
        </x-cartao>

        @if (blank($config->prefeitura) && blank($config->logo_prefeitura) && blank($config->brasao))
            <div class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                <x-icone nome="info" class="mt-0.5 size-5 shrink-0 text-slate-400" />
                <p>
                    Os documentos estão saindo sem o brasão e sem os dados da prefeitura.
                    <a href="{{ route('configuracoes.edit') }}" class="font-medium text-marca-700 underline">
                        Cadastre a identidade institucional
                    </a>
                    para que apareçam no cabeçalho.
                </p>
            </div>
        @endif
    </div>
</x-layouts.app>
