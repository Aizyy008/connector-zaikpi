@props(['label', 'value', 'meta' => null])
<x-card>
    <div class="text-xs font-extrabold uppercase tracking-wide text-muted">{{ $label }}</div>
    <div class="mt-2 text-3xl font-extrabold tracking-tight">{{ $value }}</div>
    @if ($meta)
        <div class="mt-1.5 text-sm text-muted">{{ $meta }}</div>
    @endif
</x-card>
