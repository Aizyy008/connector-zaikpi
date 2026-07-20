@props(['label', 'name', 'type' => 'text', 'value' => '', 'required' => false, 'placeholder' => '', 'autocomplete' => null, 'hint' => null])
<div>
    <label for="{{ $name }}" class="block text-xs font-bold uppercase tracking-wide text-muted mb-2">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}"
           @if ($required) required @endif
           @if ($placeholder) placeholder="{{ $placeholder }}" @endif
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           {{ $attributes->merge(['class' => 'w-full rounded-xl border border-border bg-panel-2 px-3.5 py-2.5 text-text outline-none focus:border-blue focus:ring-2 focus:ring-blue/30']) }}>
    @if ($hint)
        <p class="mt-1.5 text-xs text-muted">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p>
    @enderror
</div>
