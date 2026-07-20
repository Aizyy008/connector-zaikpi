@props(['color' => 'gray'])
@php
    // Map semantic colors to theme tokens (gray -> muted).
    $token = ['green' => 'green', 'blue' => 'blue', 'amber' => 'amber', 'red' => 'red', 'purple' => 'purple', 'gray' => 'muted'][$color] ?? 'muted';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-extrabold whitespace-nowrap']) }}
      style="background: color-mix(in srgb, var(--{{ $token }}) 14%, transparent); color: var(--{{ $token }});">
    {{ $slot }}
</span>
