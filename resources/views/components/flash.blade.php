@if (session('status'))
    <div class="rounded-xl border px-4 py-3 text-sm font-semibold"
         style="background: color-mix(in srgb, var(--green) 12%, transparent); color: var(--green); border-color: color-mix(in srgb, var(--green) 25%, transparent);">
        {{ session('status') }}
    </div>
@endif
@if (session('error'))
    <div class="rounded-xl border px-4 py-3 text-sm font-semibold"
         style="background: color-mix(in srgb, var(--red) 12%, transparent); color: var(--red); border-color: color-mix(in srgb, var(--red) 25%, transparent);">
        {{ session('error') }}
    </div>
@endif
