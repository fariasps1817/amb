@props([
    'campo',
    'rotulo',
    'url' => null,
    'ajuda' => null,
])

{{--
    Campo de upload de imagem com prévia.

    Mostra a imagem já cadastrada e, ao escolher um arquivo, troca a prévia pelo
    novo antes de salvar — assim o operador confere que o arquivo certo foi
    selecionado e não fica na dúvida se o clique funcionou.
--}}

<div
    class="rounded-lg border border-slate-200 p-3 transition"
    x-data="{
        previa: @js($url),
        nomeArquivo: null,
        selecionar(evento) {
            const arquivo = evento.target.files?.[0];

            if (! arquivo) {
                this.nomeArquivo = null;
                this.previa = @js($url);
                return;
            }

            this.nomeArquivo = arquivo.name;
            this.previa = URL.createObjectURL(arquivo);
        },
    }"
    :class="nomeArquivo ? 'border-marca-400 bg-marca-50/40' : ''"
>
    <div class="flex items-center justify-between gap-2">
        <p class="text-sm font-medium text-slate-700">{{ $rotulo }}</p>

        <span x-show="nomeArquivo" x-cloak class="shrink-0 text-[0.7rem] font-medium text-marca-700">
            novo arquivo
        </span>
    </div>

    {{--
        Prévia.

        A imagem já cadastrada é renderizada pelo servidor, para aparecer mesmo
        sem JavaScript. O Alpine apenas troca o src quando um novo arquivo é
        escolhido, e mostra o espaço vazio quando não há nada.
    --}}
    <label
        for="imagem-{{ $campo }}"
        class="mt-2 flex h-24 cursor-pointer items-center justify-center rounded-lg bg-slate-50 p-2 ring-1 ring-inset ring-slate-200 transition hover:ring-marca-400"
    >
        <img
            :src="previa"
            src="{{ $url }}"
            alt="{{ $rotulo }}"
            class="max-h-full max-w-full object-contain"
            x-show="previa"
            @if (! $url) style="display: none" @endif
        >

        <span x-show="! previa" @if ($url) style="display: none" @endif class="flex flex-col items-center gap-1 text-slate-400">
            <x-icone nome="mais" class="size-5" />
            <span class="text-xs">Clique para escolher</span>
        </span>
    </label>

    <input
        id="imagem-{{ $campo }}"
        type="file"
        name="{{ $campo }}"
        accept="image/png,image/jpeg,image/svg+xml,image/webp"
        x-on:change="selecionar($event)"
        class="mt-2 block w-full cursor-pointer text-xs text-slate-600 file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-marca-600 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-white hover:file:bg-marca-700"
    >

    <p x-show="nomeArquivo" x-cloak class="mt-1 truncate text-xs text-slate-600">
        Selecionado: <span x-text="nomeArquivo" class="font-medium"></span> — clique em
        <strong>Salvar identidade</strong> para aplicar.
    </p>

    @error($campo)
        <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
    @enderror

    @if ($ajuda)
        <p class="mt-1 text-xs text-slate-500">{{ $ajuda }}</p>
    @endif
</div>
