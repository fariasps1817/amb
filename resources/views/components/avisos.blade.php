{{--
    Mensagens de retorno das acoes (flash) e erros de validacao gerais.
    Incluido uma vez no layout, aparece em todas as telas.
--}}

@if (session('sucesso'))
    <div
        role="status"
        class="mb-4 flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
    >
        <x-icone nome="check" class="mt-0.5 size-5 shrink-0" />
        <p>{{ session('sucesso') }}</p>
    </div>
@endif

@if (session('atencao'))
    <div
        role="status"
        class="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
    >
        <x-icone nome="alerta" class="mt-0.5 size-5 shrink-0" />
        <p>{{ session('atencao') }}</p>
    </div>
@endif

@if (session('erro'))
    <div
        role="alert"
        class="mb-4 flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
    >
        <x-icone nome="erro" class="mt-0.5 size-5 shrink-0" />
        <p>{{ session('erro') }}</p>
    </div>
@endif

@if ($errors->any() && ! $errors->hasBag('default'))
    @php $total = $errors->count(); @endphp

    <div role="alert" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <div class="flex items-start gap-3">
            <x-icone nome="erro" class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-medium">
                    {{ $total === 1 ? 'Há um campo para corrigir:' : "Há {$total} campos para corrigir:" }}
                </p>
                <ul class="mt-1 list-inside list-disc space-y-0.5">
                    @foreach ($errors->all() as $mensagem)
                        <li>{{ $mensagem }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif
