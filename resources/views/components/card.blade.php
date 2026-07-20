@props(['padded' => true])
<div {{ $attributes->merge(['class' => 'rounded-[var(--radius-card)] border border-border shadow-lg overflow-hidden']) }}
     style="background: linear-gradient(180deg, var(--panel), var(--panel-2));">
    <div class="{{ $padded ? 'p-5' : '' }}">{{ $slot }}</div>
</div>
