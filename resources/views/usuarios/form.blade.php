@php $novo = ! $usuario->exists; @endphp

<x-layouts.app :titulo="$novo ? 'Novo usuário' : 'Editar usuário'" :subtitulo="$novo ? null : $usuario->nome">
    <div class="mx-auto max-w-xl space-y-5">
        <form
            id="form-usuario"
            method="POST"
            action="{{ $novo ? route('usuarios.store') : route('usuarios.update', $usuario) }}"
            class="space-y-5"
        >
            @csrf
            @unless ($novo)
                @method('PUT')
            @endunless

            <x-cartao titulo="Dados de acesso">
                <div class="grid gap-4">
                    <x-input rotulo="Nome" name="nome" value="{{ old('nome', $usuario->nome) }}" required obrigatorio maxlength="255" />

                    <x-input
                        rotulo="Usuário"
                        name="usuario"
                        value="{{ old('usuario', $usuario->usuario) }}"
                        required
                        obrigatorio
                        maxlength="255"
                        autocomplete="off"
                        placeholder="joao.silva"
                        ajuda="Somente letras, números, ponto, hífen e sublinhado."
                    />

                    <x-input rotulo="E-mail (opcional)" name="email" tipo="email" value="{{ old('email', $usuario->email) }}" maxlength="255" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input
                            :rotulo="$novo ? 'Senha' : 'Nova senha'"
                            name="password"
                            tipo="password"
                            :required="$novo"
                            :obrigatorio="$novo"
                            autocomplete="new-password"
                            inputmode="numeric"
                            :ajuda="$novo ? 'Mínimo de 4 caracteres. Pode ser apenas números.' : 'Deixe em branco para manter a senha atual.'"
                        />
                        <x-input
                            rotulo="Confirmar senha"
                            name="password_confirmation"
                            tipo="password"
                            :required="$novo"
                            :obrigatorio="$novo"
                            autocomplete="new-password"
                            inputmode="numeric"
                        />
                    </div>

                    <x-select
                        rotulo="Perfil"
                        name="perfil"
                        :opcoes="\App\Models\User::PERFIS"
                        :selecionado="$usuario->perfil ?? 'operador'"
                        required
                        obrigatorio
                        ajuda="Consulta apenas visualiza e imprime. Operador cadastra e monta escalas. Administrador também gerencia usuários."
                    />

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="ativo" value="0">
                        <input
                            type="checkbox"
                            name="ativo"
                            value="1"
                            class="size-4 rounded border-slate-300 text-marca-600 focus:ring-marca-600"
                            @checked(old('ativo', $usuario->ativo ?? true))
                        >
                        Usuário ativo — pode entrar no sistema
                    </label>
                </div>
            </x-cartao>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if (! $novo && ! $usuario->is(auth()->user()))
                    <form
                        method="POST"
                        action="{{ route('usuarios.destroy', $usuario) }}"
                        onsubmit="return confirm('Excluir o usuário {{ $usuario->nome }}?')"
                    >
                        @csrf
                        @method('DELETE')
                        <x-botao type="submit" variante="perigo" tamanho="pequeno" icone="lixeira">Excluir</x-botao>
                    </form>
                @endif
            </div>

            <div class="flex gap-2">
                <x-botao href="{{ route('usuarios.index') }}" variante="secundario">Cancelar</x-botao>
                <x-botao type="submit" form="form-usuario" icone="check">
                    {{ $novo ? 'Cadastrar usuário' : 'Salvar alterações' }}
                </x-botao>
            </div>
        </div>
    </div>
</x-layouts.app>
