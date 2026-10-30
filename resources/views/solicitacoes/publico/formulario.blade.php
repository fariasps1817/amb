@php
    use App\Enums\Locomocao;
    use App\Models\SolicitacaoDeTransporte;
    use App\Support\MunicipiosDoCeara;

    $telefones = $config->telefonesFormatados();
    $municipio = SolicitacaoDeTransporte::MUNICIPIO_DESTINO.' - '.SolicitacaoDeTransporte::UF_DESTINO;
@endphp

<x-layouts.visitante titulo="Solicitar ambulância">
    <div class="mx-auto w-full max-w-2xl">

        {{-- Identidade institucional: quem preenche precisa ver que é o canal oficial --}}
        <header class="mb-5 text-center">
            @if ($logo = $config->urlImagem('logo_prefeitura') ?? $config->urlImagem('brasao'))
                <img src="{{ $logo }}" alt="{{ $config->prefeitura }}" class="mx-auto mb-3 h-14 w-auto object-contain">
            @else
                <span class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-marca-600 shadow-sm">
                    <x-icone nome="ambulancias" class="size-6 text-white" />
                </span>
            @endif

            <h1 class="text-xl font-semibold text-slate-900">Solicitação de ambulância</h1>
            <p class="mt-0.5 text-sm text-slate-600">Paciente em alta hospitalar, do hospital até a residência em {{ SolicitacaoDeTransporte::MUNICIPIO_DESTINO }}-{{ SolicitacaoDeTransporte::UF_DESTINO }}</p>
            <p class="mt-0.5 text-xs text-slate-500">{{ $config->setor ?: 'Coordenação de Ambulâncias' }} · {{ $config->secretaria ?: 'Secretaria Municipal de Saúde' }}</p>
        </header>

        <x-avisos />

        <div role="note" class="mb-5 flex items-start gap-2.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5 text-sm text-sky-900">
            <x-icone nome="info" class="mt-0.5 size-5 shrink-0 text-sky-600" />
            <p>Para uso de <strong>profissionais do hospital</strong>, como o Serviço Social ou a chefia de enfermagem. Depois do envio, a coordenação liga para confirmar.</p>
        </div>

        <form
            method="POST"
            action="{{ route('solicitacao.enviar') }}"
            class="space-y-5"
            onsubmit="const b = this.querySelector('[type=submit]'); b.disabled = true; b.textContent = 'Enviando…';"
        >
            {{-- Identifica este envio: reenviar o mesmo formulário não gera outro pedido. --}}
            <input type="hidden" name="chave_envio" value="{{ old('chave_envio', (string) Str::uuid()) }}">

            {{-- Armadilha para robôs: fora da tela, ninguém de verdade preenche. --}}
            <div aria-hidden="true" style="position: absolute; left: -10000px; top: auto;">
                <label>
                    Não preencha este campo
                    <input type="text" name="{{ $campoArmadilha }}" tabindex="-1" autocomplete="off">
                </label>
            </div>

            <x-cartao titulo="1. Hospital solicitante">
                <div class="space-y-4">
                    <x-input nome="hospital_nome" rotulo="Nome do hospital" :value="old('hospital_nome')" required obrigatorio maxlength="150" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select nome="hospital_cidade" rotulo="Cidade" vazio="Selecione a cidade" :opcoes="MunicipiosDoCeara::opcoes()" required obrigatorio />
                        <x-input nome="hospital_bairro" rotulo="Bairro" :value="old('hospital_bairro')" required obrigatorio maxlength="100" />
                    </div>
                </div>
            </x-cartao>

            <x-cartao titulo="2. Quem está solicitando">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input nome="solicitante_nome" rotulo="Seu nome" :value="old('solicitante_nome')" required obrigatorio maxlength="120" autocomplete="name" />
                        <x-input nome="solicitante_funcao" rotulo="Sua função" :value="old('solicitante_funcao')" required obrigatorio maxlength="80" placeholder="Ex.: Assistente Social" />
                    </div>
                    <x-input
                        nome="solicitante_telefone"
                        rotulo="Telefone de contato, com DDD"
                        mascara="telefone"
                        :value="old('solicitante_telefone')"
                        required
                        obrigatorio
                        ajuda="O seu ou o do hospital, onde seja possível falar com você."
                    />
                </div>
            </x-cartao>

            <x-cartao titulo="3. Paciente">
                <div class="space-y-4">
                    <x-input nome="paciente_nome" rotulo="Nome do paciente" :value="old('paciente_nome')" required obrigatorio maxlength="150" autocomplete="off" />

                    <div class="grid grid-cols-2 gap-4">
                        <x-input nome="paciente_idade" tipo="number" rotulo="Idade" :value="old('paciente_idade')" required obrigatorio min="0" max="130" inputmode="numeric" />
                        <x-select
                            nome="paciente_idade_unidade"
                            rotulo="Anos ou meses"
                            :opcoes="['anos' => 'anos', 'meses' => 'meses']"
                            :selecionado="old('paciente_idade_unidade', 'anos')"
                        />
                    </div>

                    <x-input nome="acompanhante_nome" rotulo="Nome do acompanhante" :value="old('acompanhante_nome')" maxlength="150" autocomplete="off" ajuda="Deixe em branco se não houver." />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input nome="contato_telefone_1" rotulo="Telefone do paciente ou acompanhante" mascara="telefone" :value="old('contato_telefone_1')" required obrigatorio />
                        <x-input nome="contato_telefone_2" rotulo="Outro telefone (opcional)" mascara="telefone" :value="old('contato_telefone_2')" />
                    </div>
                </div>
            </x-cartao>

            <x-cartao titulo="4. Para onde levar" descricao="Onde a ambulância vai deixar o paciente.">
                <div class="space-y-4">
                    <x-input nome="destino_endereco" rotulo="Endereço (rua e número)" :value="old('destino_endereco')" required obrigatorio maxlength="200" autocomplete="off" />
                    <x-input nome="destino_bairro" rotulo="Bairro ou localidade" :value="old('destino_bairro')" required obrigatorio maxlength="100" autocomplete="off" />
                    <x-input nome="destino_referencia" rotulo="Ponto de referência" :value="old('destino_referencia')" maxlength="200" autocomplete="off" ajuda="Ajuda o motorista a achar a casa, principalmente na zona rural." />
                    {{-- Sem "name": o município é sempre definido pelo servidor. --}}
                    <x-input rotulo="Município" :value="$municipio" disabled ajuda="Este serviço leva pacientes somente para {{ SolicitacaoDeTransporte::MUNICIPIO_DESTINO }}." />
                </div>
            </x-cartao>

            <x-cartao titulo="5. Situação do paciente">
                <div class="space-y-5">
                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-slate-700">Locomoção <span class="text-rose-500" aria-hidden="true">*</span></legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach (Locomocao::opcoes() as $valor => $rotulo)
                                <x-opcao tipo="radio" nome="locomocao" :valor="$valor" :rotulo="$rotulo" :marcado="old('locomocao') === $valor" required />
                            @endforeach
                        </div>
                        @error('locomocao')
                            <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-slate-700">Estado</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <x-opcao nome="consciente" rotulo="Consciente" :marcado="(bool) old('consciente')" />
                            <x-opcao nome="orientado" rotulo="Orientado" :marcado="(bool) old('orientado')" />
                        </div>
                        <div class="mt-2">
                            <x-opcao nome="puerpera_com_rn" rotulo="Puérpera com recém-nascido" :marcado="(bool) old('puerpera_com_rn')" />
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-slate-700">Necessita de</legend>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <x-opcao nome="necessita_oxigenio" rotulo="Oxigênio" :marcado="(bool) old('necessita_oxigenio')" />
                            <x-opcao nome="necessita_aspirador" rotulo="Aspirador" :marcado="(bool) old('necessita_aspirador')" />
                            <x-opcao nome="necessita_tecnico" rotulo="Técnico de enfermagem" :marcado="(bool) old('necessita_tecnico')" />
                        </div>
                    </fieldset>

                    <x-textarea nome="observacoes_paciente" rotulo="Observações" linhas="3" maxlength="1000" placeholder="Outras informações importantes para o transporte">{{ old('observacoes_paciente') }}</x-textarea>
                </div>
            </x-cartao>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
                <x-opcao nome="declaracao" rotulo="Sou profissional deste hospital e as informações acima são verdadeiras." :marcado="(bool) old('declaracao')" required />
                @error('declaracao')
                    <p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                @enderror

                <x-botao type="submit" tamanho="grande" class="mt-4 w-full">Enviar solicitação</x-botao>

                <p class="mt-3 text-center text-xs text-slate-500">
                    A data e a hora são registradas automaticamente no envio (horário de Fortaleza).
                    Os dados servem somente para organizar o transporte e são vistos apenas pela coordenação.
                </p>
            </div>
        </form>

        @if ($telefones !== '')
            <p class="mt-5 text-center text-sm text-slate-600">Dúvidas? Coordenação: <span class="font-medium">{{ $telefones }}</span></p>
        @endif
    </div>

    {{-- Voltar para a página depois de enviar não pode deixar o botão travado. --}}
    <script>
        window.addEventListener('pageshow', () => {
            const botao = document.querySelector('form [type=submit]');
            botao.disabled = false;
            botao.textContent = 'Enviar solicitação';
        });
    </script>
</x-layouts.visitante>
