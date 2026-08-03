@php
    $novo = ! $unidade->exists;
    $regimeAtual = old('regime', $unidade->exists ? $unidade->regimeNotacao() : '24/72');
@endphp

<x-layouts.app
    :titulo="$novo ? 'Nova unidade' : 'Editar unidade'"
    :subtitulo="$novo ? null : $unidade->nomeCompleto()"
>
    <div class="mx-auto max-w-3xl space-y-5">
        <form
            id="form-unidade"
            method="POST"
            action="{{ $novo ? route('unidades.store') : route('unidades.update', $unidade) }}"
            class="space-y-5"
        >
            @csrf
            @unless ($novo)
                @method('PUT')
            @endunless

            <x-cartao titulo="Identificação">
                <div class="grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-4">
                        <x-input
                            rotulo="Nome da unidade"
                            name="nome"
                            value="{{ old('nome', $unidade->nome) }}"
                            required
                            obrigatorio
                            maxlength="255"
                            placeholder="UPA Centro"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-input
                            rotulo="Sigla"
                            name="sigla"
                            value="{{ old('sigla', $unidade->sigla) }}"
                            required
                            obrigatorio
                            maxlength="30"
                            placeholder="UPA"
                            class="uppercase"
                            ajuda="Aparece na coluna de lotação da planilha."
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <x-select
                            rotulo="Tipo"
                            name="tipo"
                            :opcoes="\App\Models\Unidade::TIPOS"
                            :selecionado="$unidade->tipo"
                            vazio="Selecione"
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <x-input
                            rotulo="Ordem de impressão"
                            name="ordem"
                            tipo="number"
                            value="{{ old('ordem', $unidade->ordem ?? 0) }}"
                            min="0"
                            max="9999"
                            ajuda="Define a sequência dos blocos na planilha da escala."
                        />
                    </div>
                </div>
            </x-cartao>

            {{-- ------------------------------------------------------------
                 Regime de plantão — o dado que dimensiona o efetivo
                 ------------------------------------------------------------ --}}

            <x-cartao
                titulo="Regime de plantão"
                descricao="Todas as ambulâncias desta unidade operam neste regime. É ele que define quantos motoristas cada veículo precisa para fechar o ciclo sem lacuna."
            >
                <div
                    x-data="{
                        regime: '{{ $regimeAtual }}',
                        get motoristas() {
                            const [trabalho, descanso] = this.regime.split('/').map(Number);
                            if (! trabalho || Number.isNaN(descanso)) return null;
                            if ((trabalho + descanso) % trabalho !== 0) return null;
                            return (trabalho + descanso) / trabalho;
                        },
                    }"
                    class="space-y-4"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select
                            rotulo="Regime"
                            name="regime"
                            :opcoes="\App\Support\Regime::predefinidos()"
                            :selecionado="$regimeAtual"
                            required
                            obrigatorio
                            x-model="regime"
                        />

                        <div class="flex items-end">
                            <div class="w-full rounded-lg bg-marca-50 px-3.5 py-2.5 ring-1 ring-inset ring-marca-200">
                                <p class="text-xs text-marca-700">Motoristas por ambulância</p>
                                <p class="text-xl font-semibold text-marca-900 tabular-nums" x-text="motoristas ?? '—'">
                                    {{ $unidade->exists ? $unidade->motoristasPorAmbulancia() : 4 }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-slate-500">
                        Exemplo: em <strong>24/72</strong> o motorista trabalha 24 horas e descansa 72, então são
                        necessários <strong>4 motoristas</strong> por ambulância — um por dia, revezando de quatro em
                        quatro dias. Em <strong>24/48</strong> são <strong>3 motoristas</strong>.
                    </p>

                    @error('regime')
                        <p class="text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </x-cartao>

            <x-cartao titulo="Endereço">
                <div class="grid gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-4">
                        <x-input rotulo="Logradouro" name="endereco" value="{{ old('endereco', $unidade->endereco) }}" maxlength="255" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input rotulo="CEP" name="cep" value="{{ old('cep', $unidade->cep) }}" maxlength="9" inputmode="numeric" placeholder="00000-000" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input rotulo="Bairro / Localidade" name="bairro" value="{{ old('bairro', $unidade->bairro) }}" maxlength="255" />
                    </div>
                    <div class="sm:col-span-3">
                        <x-input rotulo="Cidade" name="cidade" value="{{ old('cidade', $unidade->cidade) }}" maxlength="255" />
                    </div>
                    <div class="sm:col-span-1">
                        <x-input rotulo="UF" name="uf" value="{{ old('uf', $unidade->uf) }}" maxlength="2" class="uppercase" />
                    </div>
                </div>
            </x-cartao>

            <x-cartao titulo="Responsável e contato">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input rotulo="Responsável" name="responsavel" value="{{ old('responsavel', $unidade->responsavel) }}" maxlength="255" />
                    <x-input rotulo="Cargo" name="cargo_responsavel" value="{{ old('cargo_responsavel', $unidade->cargo_responsavel) }}" maxlength="255" />
                    <x-input rotulo="Telefone" name="telefone_1" tipo="tel" value="{{ old('telefone_1', \App\Support\Telefone::formatar($unidade->telefone_1)) }}" inputmode="tel" />
                    <x-input rotulo="Telefone alternativo" name="telefone_2" tipo="tel" value="{{ old('telefone_2', \App\Support\Telefone::formatar($unidade->telefone_2)) }}" inputmode="tel" />

                    <div class="sm:col-span-2">
                        <x-input rotulo="E-mail" name="email" tipo="email" value="{{ old('email', $unidade->email) }}" maxlength="255" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-textarea rotulo="Observação" name="observacao" linhas="2" maxlength="1000">{{ old('observacao', $unidade->observacao) }}</x-textarea>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="ativo" value="0">
                            <input
                                type="checkbox"
                                name="ativo"
                                value="1"
                                class="size-4 rounded border-slate-300 text-marca-600 focus:ring-marca-600"
                                @checked(old('ativo', $unidade->ativo ?? true))
                            >
                            Unidade ativa — entra na montagem das próximas escalas
                        </label>
                    </div>
                </div>
            </x-cartao>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if (! $novo && auth()->user()->podeEditar())
                    <form
                        method="POST"
                        action="{{ route('unidades.destroy', $unidade) }}"
                        onsubmit="return confirm('Excluir a unidade {{ $unidade->sigla }}? Se ela consta em escalas registradas, será apenas desativada.')"
                    >
                        @csrf
                        @method('DELETE')
                        <x-botao type="submit" variante="perigo" tamanho="pequeno" icone="lixeira">Excluir</x-botao>
                    </form>
                @endif
            </div>

            <div class="flex gap-2">
                <x-botao href="{{ route('unidades.index') }}" variante="secundario">Cancelar</x-botao>
                <x-botao type="submit" form="form-unidade" icone="check">
                    {{ $novo ? 'Cadastrar unidade' : 'Salvar alterações' }}
                </x-botao>
            </div>
        </div>
    </div>
</x-layouts.app>
