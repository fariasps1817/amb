@php $novo = ! $motorista->exists; @endphp

<x-layouts.app
    :titulo="$novo ? 'Novo motorista' : 'Editar motorista'"
    :subtitulo="$novo ? null : $motorista->nome_completo"
>
    {{--
        O x-data fica no contêiner, e não no <form>, porque o botão de excluir
        precisa de um formulário próprio — formulários não podem ser aninhados.
    --}}
    <div
        class="mx-auto max-w-3xl space-y-5"
        x-data="{ vinculo: '{{ old('vinculo', $motorista->vinculo?->value ?? 'contrato') }}' }"
    >
        <form
            id="form-motorista"
            method="POST"
            action="{{ $novo ? route('motoristas.store') : route('motoristas.update', $motorista) }}"
            class="space-y-5"
        >
            @csrf
            @unless ($novo)
                @method('PUT')
            @endunless

        {{-- ------------------------------------------------------------
             Identificação
             ------------------------------------------------------------ --}}

        <x-cartao titulo="Identificação">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input
                        rotulo="Nome completo"
                        name="nome_completo"
                        value="{{ old('nome_completo', $motorista->nome_completo) }}"
                        required
                        obrigatorio
                        maxlength="255"
                        placeholder="JOÃO BERNARDO DE OLIVEIRA"
                        class="uppercase"
                    />
                </div>

                <div class="sm:col-span-2">
                    <x-input
                        rotulo="Nome curto ou tratamento"
                        name="nome_curto"
                        value="{{ old('nome_curto', $motorista->nome_curto) }}"
                        required
                        obrigatorio
                        maxlength="60"
                        placeholder="JOÃO BERNARDO"
                        class="uppercase"
                        ajuda="Nome que aparece na planilha da escala, onde o espaço é curto."
                    />
                </div>

                <x-input
                    rotulo="CPF"
                    name="cpf"
                    value="{{ old('cpf', $motorista->cpfFormatado()) }}"
                    mascara="cpf"
                />

                <x-input
                    rotulo="Data de nascimento"
                    name="data_nascimento"
                    tipo="date"
                    value="{{ old('data_nascimento', $motorista->data_nascimento?->toDateString()) }}"
                />

                <x-input
                    rotulo="Matrícula"
                    name="matricula"
                    value="{{ old('matricula', $motorista->matricula) }}"
                    maxlength="30"
                />

                <x-select
                    rotulo="Situação"
                    name="status"
                    :opcoes="\App\Enums\StatusMotorista::opcoes()"
                    :selecionado="$motorista->status?->value ?? 'ativo'"
                    required
                    obrigatorio
                    ajuda="Somente motoristas ativos entram na montagem da escala."
                />
            </div>
        </x-cartao>

        {{-- ------------------------------------------------------------
             Vínculo funcional
             ------------------------------------------------------------ --}}

        <x-cartao
            titulo="Vínculo funcional"
            descricao="Contrato temporário exige data de término, para o sistema avisar quando o motorista deixa de poder ser escalado."
        >
            <div class="grid gap-4 sm:grid-cols-3">
                <x-select
                    rotulo="Tipo de vínculo"
                    name="vinculo"
                    :opcoes="\App\Enums\Vinculo::opcoes()"
                    :selecionado="$motorista->vinculo?->value ?? 'contrato'"
                    required
                    obrigatorio
                    x-model="vinculo"
                />

                <x-input
                    rotulo="Início do vínculo"
                    name="vinculo_inicio"
                    tipo="date"
                    value="{{ old('vinculo_inicio', $motorista->vinculo_inicio?->toDateString()) }}"
                />

                <div x-show="vinculo === 'contrato'" x-cloak>
                    <x-input
                        rotulo="Fim do contrato"
                        name="vinculo_fim"
                        tipo="date"
                        value="{{ old('vinculo_fim', $motorista->vinculo_fim?->toDateString()) }}"
                        x-bind:required="vinculo === 'contrato'"
                    />
                </div>
            </div>
        </x-cartao>

        {{-- ------------------------------------------------------------
             Habilitação
             ------------------------------------------------------------ --}}

        <x-cartao titulo="Habilitação">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-input
                    rotulo="Número da CNH"
                    name="cnh_numero"
                    value="{{ old('cnh_numero', $motorista->cnh_numero) }}"
                    inputmode="numeric"
                    maxlength="20"
                />

                <x-input
                    rotulo="Categoria"
                    name="cnh_categoria"
                    value="{{ old('cnh_categoria', $motorista->cnh_categoria) }}"
                    maxlength="10"
                    placeholder="D"
                    class="uppercase"
                    list="categorias-cnh"
                />
                <datalist id="categorias-cnh">
                    @foreach (['B', 'C', 'D', 'E', 'AB', 'AC', 'AD', 'AE'] as $categoria)
                        <option value="{{ $categoria }}"></option>
                    @endforeach
                </datalist>

                <x-input
                    rotulo="Validade da CNH"
                    name="cnh_validade"
                    tipo="date"
                    value="{{ old('cnh_validade', $motorista->cnh_validade?->toDateString()) }}"
                />
            </div>

            @if ($motorista->exists && $motorista->cnhVencida())
                <p class="mt-3 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                    <x-icone nome="erro" class="size-4 shrink-0" />
                    CNH vencida em {{ $motorista->cnh_validade->format('d/m/Y') }}. Este motorista não pode ser escalado.
                </p>
            @endif
        </x-cartao>

        {{-- ------------------------------------------------------------
             Contato
             ------------------------------------------------------------ --}}

        <x-cartao
            titulo="Contato"
            descricao="O telefone principal recebe a mensagem de WhatsApp com os dias de plantão."
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input
                    rotulo="Telefone principal (WhatsApp)"
                    name="telefone_1"
                    tipo="tel"
                    value="{{ old('telefone_1', $motorista->telefoneFormatado()) }}"
                    mascara="telefone"
                />

                <x-input
                    rotulo="Telefone alternativo"
                    name="telefone_2"
                    tipo="tel"
                    value="{{ old('telefone_2', $motorista->telefone2Formatado()) }}"
                    mascara="telefone"
                />

                <div class="sm:col-span-2">
                    <x-textarea rotulo="Observação" name="observacao" linhas="3" maxlength="1000">{{ old('observacao', $motorista->observacao) }}</x-textarea>
                </div>
            </div>
            </x-cartao>
        </form>

        {{-- ------------------------------------------------------------
             Ações
             ------------------------------------------------------------ --}}

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if (! $novo && auth()->user()->podeEditar())
                    <form
                        method="POST"
                        action="{{ route('motoristas.destroy', $motorista) }}"
                        onsubmit="return confirm('Excluir o motorista {{ $motorista->nome_curto }}? Se ele já participa de escalas, será apenas inativado para preservar o histórico.')"
                    >
                        @csrf
                        @method('DELETE')
                        <x-botao type="submit" variante="perigo" tamanho="pequeno" icone="lixeira">Excluir</x-botao>
                    </form>
                @endif
            </div>

            <div class="flex gap-2">
                <x-botao href="{{ route('motoristas.index') }}" variante="secundario">Cancelar</x-botao>
                <x-botao type="submit" form="form-motorista" icone="check">
                    {{ $novo ? 'Cadastrar motorista' : 'Salvar alterações' }}
                </x-botao>
            </div>
        </div>
    </div>
</x-layouts.app>
