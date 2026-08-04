<x-layouts.app
    titulo="Identidade institucional"
    subtitulo="Dados e imagens aplicados no cabeçalho e rodapé dos documentos gerados"
>
    <form
        method="POST"
        action="{{ route('configuracoes.update') }}"
        enctype="multipart/form-data"
        class="mx-auto max-w-3xl space-y-5"
    >
        @csrf
        @method('PUT')

        {{-- ------------------------------------------------------------
             Imagens
             ------------------------------------------------------------ --}}

        <x-cartao
            titulo="Logos e brasão"
            descricao="PNG, JPG, SVG ou WEBP até 2 MB. Prefira imagens com fundo transparente e altura mínima de 120 px."
        >
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($imagens as $campo => $rotulo)
                    <x-campo-imagem
                        :campo="$campo"
                        :rotulo="$rotulo"
                        :url="$configuracao->urlImagem($campo)"
                    />
                @endforeach
            </div>

            <p class="mt-3 text-xs text-slate-500">
                As imagens só são gravadas ao clicar em <strong>Salvar identidade</strong>, no fim da página.
                @if (collect(array_keys($imagens))->contains(fn ($c) => filled($configuracao->{$c})))
                    Para remover alguma, use os botões no fim da página.
                @endif
            </p>
        </x-cartao>

        {{-- ------------------------------------------------------------
             Órgão
             ------------------------------------------------------------ --}}

        <x-cartao titulo="Órgão">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input rotulo="Município" name="municipio" value="{{ old('municipio', $configuracao->municipio) }}" maxlength="255" placeholder="Cascavel" />
                <x-input rotulo="Prefeitura" name="prefeitura" value="{{ old('prefeitura', $configuracao->prefeitura) }}" maxlength="255" placeholder="Prefeitura Municipal de Cascavel" />

                <div class="sm:col-span-2">
                    <x-input rotulo="Secretaria" name="secretaria" value="{{ old('secretaria', $configuracao->secretaria) }}" maxlength="255" placeholder="Secretaria Municipal de Saúde" />
                </div>
                <div class="sm:col-span-2">
                    <x-input rotulo="Setor" name="setor" value="{{ old('setor', $configuracao->setor) }}" maxlength="255" placeholder="Coordenação de Ambulâncias" />
                </div>
                <div class="sm:col-span-2">
                    <x-input rotulo="Slogan" name="slogan" value="{{ old('slogan', $configuracao->slogan) }}" maxlength="255" placeholder="Agora cuidando de você." />
                </div>

                <x-input rotulo="CNPJ" name="cnpj" value="{{ old('cnpj', $configuracao->cnpj) }}" maxlength="18" />
                <x-input rotulo="Site" name="site" value="{{ old('site', $configuracao->site) }}" maxlength="255" placeholder="www.cascavel.ce.gov.br" />
            </div>
        </x-cartao>

        {{-- ------------------------------------------------------------
             Endereço e contato
             ------------------------------------------------------------ --}}

        <x-cartao titulo="Endereço e contato">
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-4">
                    <x-input rotulo="Logradouro" name="endereco" value="{{ old('endereco', $configuracao->endereco) }}" maxlength="255" />
                </div>
                <div class="sm:col-span-2">
                    <x-input rotulo="CEP" name="cep" value="{{ old('cep', $configuracao->cep) }}" maxlength="9" inputmode="numeric" />
                </div>
                <div class="sm:col-span-2">
                    <x-input rotulo="Bairro" name="bairro" value="{{ old('bairro', $configuracao->bairro) }}" maxlength="255" />
                </div>
                <div class="sm:col-span-3">
                    <x-input rotulo="Cidade" name="cidade" value="{{ old('cidade', $configuracao->cidade) }}" maxlength="255" />
                </div>
                <div class="sm:col-span-1">
                    <x-input rotulo="UF" name="uf" value="{{ old('uf', $configuracao->uf) }}" maxlength="2" class="uppercase" />
                </div>

                <div class="sm:col-span-2">
                    <x-input
                        rotulo="Telefone"
                        name="telefone_1"
                        tipo="tel"
                        value="{{ old('telefone_1', \App\Support\Telefone::formatar($configuracao->telefone_1)) }}"
                        inputmode="tel"
                        ajuda="Aparece no topo da planilha."
                    />
                </div>
                <div class="sm:col-span-2">
                    <x-input
                        rotulo="Telefone alternativo"
                        name="telefone_2"
                        tipo="tel"
                        value="{{ old('telefone_2', \App\Support\Telefone::formatar($configuracao->telefone_2)) }}"
                        inputmode="tel"
                    />
                </div>
                <div class="sm:col-span-2">
                    <x-input rotulo="E-mail" name="email" tipo="email" value="{{ old('email', $configuracao->email) }}" maxlength="255" />
                </div>
            </div>
        </x-cartao>

        {{-- ------------------------------------------------------------
             Assinatura dos documentos
             ------------------------------------------------------------ --}}

        <x-cartao titulo="Responsável e rodapé">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input rotulo="Responsável pelo setor" name="responsavel_setor" value="{{ old('responsavel_setor', $configuracao->responsavel_setor) }}" maxlength="255" />
                <x-input rotulo="Cargo" name="cargo_responsavel" value="{{ old('cargo_responsavel', $configuracao->cargo_responsavel) }}" maxlength="255" placeholder="Coordenador de Ambulâncias" />

                <div class="sm:col-span-2">
                    <x-input
                        rotulo="Rodapé dos documentos"
                        name="rodape_documentos"
                        value="{{ old('rodape_documentos', $configuracao->rodape_documentos) }}"
                        maxlength="255"
                        placeholder="PMC - SMS - COORD AMBULÂNCIAS"
                        ajuda="Texto do canto inferior esquerdo das páginas. Se vazio, usa secretaria e setor."
                    />
                </div>
            </div>
        </x-cartao>

        {{-- ------------------------------------------------------------
             Layout da planilha
             ------------------------------------------------------------ --}}

        <x-cartao
            titulo="Layout da planilha de plantões"
            descricao="Como a placa e a lotação de cada ambulância aparecem no documento distribuído às unidades."
        >
            @php $layoutAtual = old('layout_planilha', $configuracao->layout_planilha ?: 'classico'); @endphp

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="cursor-pointer">
                    <input
                        type="radio"
                        name="layout_planilha"
                        value="classico"
                        class="peer sr-only"
                        @checked($layoutAtual === 'classico')
                    >
                    <div class="h-full rounded-lg p-3 ring-1 ring-slate-200 transition peer-checked:bg-marca-50 peer-checked:ring-2 peer-checked:ring-marca-600">
                        <p class="text-sm font-medium text-slate-900">Clássico</p>
                        <p class="mt-0.5 text-xs text-slate-600">
                            Colunas <strong>P</strong> e <strong>LOT</strong> à esquerda, como no documento
                            que a secretaria já utiliza.
                        </p>
                        <pre class="mt-2 overflow-x-auto rounded bg-white p-2 text-[0.6rem] leading-tight text-slate-600 ring-1 ring-slate-200">Nº CONDUTOR      FONE       P       LOT     01 02 03
1  JOÃO BERNARDO  98692-6853 THQ4H34 SEDE 1  X
2  MARIA DIVANIR  98849-2354         24/72      X</pre>
                    </div>
                </label>

                <label class="cursor-pointer">
                    <input
                        type="radio"
                        name="layout_planilha"
                        value="agrupado"
                        class="peer sr-only"
                        @checked($layoutAtual === 'agrupado')
                    >
                    <div class="h-full rounded-lg p-3 ring-1 ring-slate-200 transition peer-checked:bg-marca-50 peer-checked:ring-2 peer-checked:ring-marca-600">
                        <p class="text-sm font-medium text-slate-900">Agrupado</p>
                        <p class="mt-0.5 text-xs text-slate-600">
                            Faixa de identificação antes de cada ambulância. Sobra mais espaço para o nome
                            e o telefone.
                        </p>
                        <pre class="mt-2 overflow-x-auto rounded bg-white p-2 text-[0.6rem] leading-tight text-slate-600 ring-1 ring-slate-200">Nº CONDUTOR            FONE       01 02 03
── THQ4H34 · SEDE 1 · 24/72 ──────────────
1  JOÃO BERNARDO       98692-6853 X
2  MARIA DIVANIR       98849-2354    X</pre>
                    </div>
                </label>
            </div>

            @error('layout_planilha')
                <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
            @enderror

            <p class="mt-3 text-xs text-slate-500">
                Você pode conferir os dois a qualquer momento: na tela de documentos de uma escala, use
                <code>?layout=classico</code> ou <code>?layout=agrupado</code> no fim do endereço.
            </p>
        </x-cartao>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <x-botao href="{{ route('painel') }}" variante="secundario">Cancelar</x-botao>
            <x-botao type="submit" icone="check">Salvar identidade</x-botao>
        </div>
    </form>

    {{-- Remoção de imagens: formulários próprios, fora do principal --}}
    @php $comImagem = collect($imagens)->filter(fn ($r, $c) => filled($configuracao->{$c})); @endphp

    @if ($comImagem->isNotEmpty() && auth()->user()->podeEditar())
        <div class="mx-auto mt-5 max-w-3xl">
            <x-cartao titulo="Remover imagens">
                <div class="flex flex-wrap gap-2">
                    @foreach ($comImagem as $campo => $rotulo)
                        <form
                            method="POST"
                            action="{{ route('configuracoes.remover-imagem', $campo) }}"
                            onsubmit="return confirm('Remover {{ $rotulo }}?')"
                        >
                            @csrf
                            @method('DELETE')
                            <x-botao type="submit" variante="secundario" tamanho="pequeno" icone="lixeira">
                                {{ $rotulo }}
                            </x-botao>
                        </form>
                    @endforeach
                </div>
            </x-cartao>
        </div>
    @endif
</x-layouts.app>
