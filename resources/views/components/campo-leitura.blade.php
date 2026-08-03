@props([
    'rotulo',
    'valor' => null,
    'complemento' => null,
    'alerta' => null,
])

<div>
    <dt class="text-xs text-slate-500">{{ $rotulo }}</dt>
    <dd class="mt-0.5 text-sm {{ $alerta ? 'font-medium text-rose-600' : 'text-slate-900' }}">
        {{ filled($valor) ? $valor : '—' }}

        @if ($alerta)
            <span class="text-xs font-normal">({{ $alerta }})</span>
        @elseif ($complemento)
            <span class="text-xs text-slate-500">({{ $complemento }})</span>
        @endif
    </dd>
</div>
