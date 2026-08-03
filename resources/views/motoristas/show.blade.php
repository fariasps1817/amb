<x-layouts.app :titulo="$motorista->nome_curto" :subtitulo="$motorista->nome_completo">
    <x-slot:acoes>
        <x-botao href="{{ route('motoristas.index') }}" variante="secundario" tamanho="pequeno" icone="seta-esquerda">
            <span class="hidden sm:inline">Voltar</span>
        </x-botao>
        @if (auth()->user()->podeEditar())
            <x-botao href="{{ route('motoristas.edit', $motorista) }}" tamanho="pequeno" icone="lapis">
                <span class="hidden sm:inline">Editar</span>
            </x-botao>
        @endif
    </x-slot:acoes>

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- Dados cadastrais --}}
        <div class="space-y-5 lg:col-span-2">
            <x-cartao titulo="Dados cadastrais">
                <x-slot:acoes>
                    <x-badge :cor="$motorista->status->corBadge()">{{ $motorista->status->rotulo() }}</x-badge>
                </x-slot:acoes>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-3.5 sm:grid-cols-3">
                    <x-campo-leitura rotulo="CPF" :valor="$motorista->cpfFormatado()" />
                    <x-campo-leitura
                        rotulo="Nascimento"
                        :valor="$motorista->data_nascimento?->format('d/m/Y')"
                        :complemento="$motorista->idade() ? $motorista->idade().' anos' : null"
                    />
                    <x-campo-leitura rotulo="Matrícula" :valor="$motorista->matricula" />

                    <x-campo-leitura rotulo="Vínculo" :valor="$motorista->vinculo->rotulo()" />
                    <x-campo-leitura rotulo="Início do vínculo" :valor="$motorista->vinculo_inicio?->format('d/m/Y')" />
                    <x-campo-leitura
                        rotulo="Fim do contrato"
                        :valor="$motorista->vinculo_fim?->format('d/m/Y')"
                        :alerta="$motorista->contratoEncerrado() ? 'encerrado' : ($motorista->contratoVencendo() ? 'a vencer' : null)"
                    />

                    <x-campo-leitura rotulo="CNH" :valor="$motorista->cnh_numero" />
                    <x-campo-leitura rotulo="Categoria" :valor="$motorista->cnh_categoria" />
                    <x-campo-leitura
                        rotulo="Validade da CNH"
                        :valor="$motorista->cnh_validade?->format('d/m/Y')"
                        :alerta="$motorista->cnhVencida() ? 'vencida' : ($motorista->cnhVencendo() ? 'a vencer' : null)"
                    />

                    <x-campo-leitura rotulo="Telefone (WhatsApp)" :valor="$motorista->telefoneFormatado()" />
                    <x-campo-leitura rotulo="Telefone alternativo" :valor="$motorista->telefone2Formatado()" />
                </dl>

                @if ($motorista->observacao)
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="text-xs font-medium text-slate-500">Observação</p>
                        <p class="mt-1 text-sm whitespace-pre-line text-slate-700">{{ $motorista->observacao }}</p>
                    </div>
                @endif
            </x-cartao>

            {{-- Histórico de lotações --}}
            <x-cartao titulo="Histórico de lotações" descricao="Últimos 12 meses de escala">
                @forelse ($lotacoes as $lotacao)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 py-2.5 first:pt-0 last:border-0">
                        <div class="min-w-0">
                            <a href="{{ route('escalas.show', $lotacao->escala) }}" class="text-sm font-medium text-slate-900 hover:text-marca-700">
                                {{ $lotacao->escala->referenciaLonga() }}
                            </a>
                            <p class="text-xs text-slate-500">
                                {{ $lotacao->rotuloLotacao() }}
                                @if ($lotacao->escalado() && $lotacao->posto)
                                    · {{ $lotacao->posto->rotuloPlaca() }} · {{ $lotacao->posto->regimeNotacao() }}
                                @endif
                                @if ($lotacao->textoOcorrencia())
                                    · {{ $lotacao->textoOcorrencia() }}
                                @endif
                            </p>
                        </div>
                        <span class="shrink-0 text-xs text-slate-500 tabular-nums">
                            {{ $lotacao->plantoes_previstos }} plantão(ões)
                        </span>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">
                        Este motorista ainda não foi incluído em nenhuma escala.
                    </p>
                @endforelse
            </x-cartao>
        </div>

        {{-- Próximos plantões --}}
        <div>
            <x-cartao titulo="Próximos plantões">
                @forelse ($proximosPlantoes as $plantao)
                    <div class="flex items-center gap-3 border-b border-slate-100 py-2.5 first:pt-0 last:border-0">
                        <div class="w-11 shrink-0 rounded-lg bg-marca-50 py-1 text-center ring-1 ring-marca-100">
                            <p class="text-sm font-semibold text-marca-800 tabular-nums">{{ $plantao->data->format('d') }}</p>
                            <p class="text-[0.6rem] uppercase text-marca-600">{{ $plantao->data->translatedFormat('M') }}</p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-slate-800">{{ $plantao->posto?->rotuloLotacao() }}</p>
                            <p class="truncate text-xs text-slate-500">
                                {{ $plantao->posto?->rotuloPlaca() }} ·
                                {{ $plantao->diaSemanaCurto() }}
                            </p>
                        </div>
                        @if ($plantao->ajuste_manual)
                            <x-badge cor="bg-amber-100 text-amber-800 ring-amber-200">troca</x-badge>
                        @endif
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">Nenhum plantão futuro registrado.</p>
                @endforelse
            </x-cartao>
        </div>
    </div>
</x-layouts.app>
