@props([
    'tipo' => 'checkbox',
    'nome',
    'valor' => '1',
    'rotulo',
    'marcado' => false,
])

{{-- Marcar ou escolher, com a linha inteira tocável: bom alvo para o dedo no celular. --}}
<label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg bg-white px-3 py-2.5 text-sm text-slate-800 ring-1 ring-inset ring-slate-300 transition has-checked:bg-marca-50 has-checked:ring-marca-500">
    <input
        type="{{ $tipo }}"
        name="{{ $nome }}"
        value="{{ $valor }}"
        @checked($marcado)
        {{ $attributes->merge(['class' => 'size-5 shrink-0 border-slate-300 text-marca-600 focus:ring-marca-600 '.($tipo === 'checkbox' ? 'rounded' : '')]) }}
    >
    <span>{{ $rotulo }}</span>
</label>
