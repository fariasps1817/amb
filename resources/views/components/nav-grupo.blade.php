@props(['titulo' => null])

<div>
    @if ($titulo)
        <p class="px-3 pb-1.5 text-[0.7rem] font-semibold uppercase tracking-wider text-marca-300">
            {{ $titulo }}
        </p>
    @endif

    <div class="space-y-0.5">
        {{ $slot }}
    </div>
</div>
