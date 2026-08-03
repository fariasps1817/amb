@props([
    'cor' => 'bg-slate-100 text-slate-700 ring-slate-200',
])

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.$cor,
]) }}>
    {{ $slot }}
</span>
