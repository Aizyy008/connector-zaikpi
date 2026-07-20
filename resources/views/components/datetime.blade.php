@props([
    'value' => null,
    'format' => 'Y-m-d H:i:s',
    'fallback' => '—',
    'tz' => true,
])
@php
    // Stored timestamps are UTC; convert to the configured display timezone so
    // the admin panel shows local time (e.g. Europe/Athens) consistently.
    $zone = config('app.display_timezone', config('app.timezone'));
    $carbon = $value ? \Illuminate\Support\Carbon::parse($value)->timezone($zone) : null;
@endphp
@if ($carbon)
    <span {{ $attributes }} title="{{ $carbon->format('Y-m-d H:i:s') }} ({{ $carbon->format('T') }})">{{ $carbon->format($format) }}@if ($tz) <span class="text-muted">{{ $carbon->format('T') }}</span>@endif</span>
@else
    {{ $fallback }}
@endif
