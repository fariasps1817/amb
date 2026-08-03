@php $novo = ! $ambulancia->exists; @endphp

<x-layouts.app
    :titulo="$novo ? 'Nova ambulância' : 'Editar ambulância'"
    :subtitulo="$novo ? null : $ambulancia->placaFormatada()"
>
    <div class="mx-auto max-w-3xl space-y-5">
        <form
            id="form-ambulancia"
            method="POST"
            action="{{ $novo ? route('ambulancias.store') : route('ambulancias.update', $ambulancia) }}"
            class="space-y-5"
        >
            @csrf
            @unless ($novo)
                @method('PUT')
            @endunless

            <x-cartao titulo="Identificação do veículo">
                <div class="grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <x-input
                            rotulo="Placa"
                            name="placa"
                            value="{{ old('placa', $ambulancia->placaFormatada()) }}"
                            required
                            obrigatorio
                            maxlength="8"
                            placeholder="ABC1D23"
                            class="uppercase font-medium tracking-wide"
                            ajuda="Padrão antigo ou Mercosul."
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-input
                            rotulo="RENAVAM"
                            name="renavam"
                            value="{{ old('renavam', $ambulancia->renavam) }}"
                            inputmode="numeric"
                            maxlength="11"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-select
                            rotulo="Vínculo"
                            name="vinculo"
                            :opcoes="\App\Enums\VinculoAmbulancia::opcoes()"
                            :selecionado="$ambulancia->vinculo?->value ?? 'propria'"
                            required
                            obrigatorio
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-input rotulo="Marca" name="marca" value="{{ old('marca', $ambulancia->marca) }}" maxlength="60" placeholder="Fiat" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input rotulo="Modelo" name="modelo" value="{{ old('modelo', $ambulancia->modelo) }}" maxlength="60" placeholder="Ducato" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-select
                            rotulo="Tipo"
                            name="tipo"
                            :opcoes="\App\Models\Ambulancia::TIPOS"
                            :selecionado="$ambulancia->tipo"
                            vazio="Selecione"
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <x-input
                            rotulo="Ano de fabricação"
                            name="ano_fabricacao"
                            tipo="number"
                            value="{{ old('ano_fabricacao', $ambulancia->ano_fabricacao) }}"
                            min="1980"
                            max="{{ now()->year + 2 }}"
                            inputmode="numeric"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <x-input
                            rotulo="Ano do modelo"
                            name="ano_modelo"
                            tipo="number"
                            value="{{ old('ano_modelo', $ambulancia->ano_modelo) }}"
                            min="1980"
                            max="{{ now()->year + 2 }}"
                            inputmode="numeric"
                        />
                    </div>
                </div>
            </x-cartao>

            <x-cartao
                titulo="Lotação"
                descricao="A unidade informada aqui é apenas a lotação padrão. Ao montar a escala de cada mês você pode remanejar o veículo para outra unidade."
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-select
                            rotulo="Unidade padrão"
                            name="unidade_id"
                            :opcoes="$unidades"
                            :selecionado="$ambulancia->unidade_id"
                            vazio="Sem lotação definida"
                            ajuda="O regime da unidade define quantos motoristas este veículo vai precisar."
                        />
                    </div>

                    <x-input
                        rotulo="Identificação na planilha"
                        name="identificacao"
                        value="{{ old('identificacao', $ambulancia->identificacao) }}"
                        maxlength="40"
                        placeholder="SEDE 1"
                        class="uppercase"
                        ajuda="Use quando a unidade tem mais de uma ambulância (SEDE 1, SEDE 2)."
                    />

                    <div class="flex items-end">
                        <label class="flex items-center gap-2 pb-2.5 text-sm text-slate-700">
                            <input type="hidden" name="ativo" value="0">
                            <input
                                type="checkbox"
                                name="ativo"
                                value="1"
                                class="size-4 rounded border-slate-300 text-marca-600 focus:ring-marca-600"
                                @checked(old('ativo', $ambulancia->ativo ?? true))
                            >
                            Ambulância ativa
                        </label>
                    </div>

                    <div class="sm:col-span-2">
                        <x-textarea rotulo="Observação" name="observacao" linhas="2" maxlength="1000">{{ old('observacao', $ambulancia->observacao) }}</x-textarea>
                    </div>
                </div>
            </x-cartao>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if (! $novo && auth()->user()->podeEditar())
                    <form
                        method="POST"
                        action="{{ route('ambulancias.destroy', $ambulancia) }}"
                        onsubmit="return confirm('Excluir a ambulância {{ $ambulancia->placaFormatada() }}? Se ela consta em escalas registradas, será apenas desativada.')"
                    >
                        @csrf
                        @method('DELETE')
                        <x-botao type="submit" variante="perigo" tamanho="pequeno" icone="lixeira">Excluir</x-botao>
                    </form>
                @endif
            </div>

            <div class="flex gap-2">
                <x-botao href="{{ route('ambulancias.index') }}" variante="secundario">Cancelar</x-botao>
                <x-botao type="submit" form="form-ambulancia" icone="check">
                    {{ $novo ? 'Cadastrar ambulância' : 'Salvar alterações' }}
                </x-botao>
            </div>
        </div>
    </div>
</x-layouts.app>
