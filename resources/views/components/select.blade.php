@props(['label', 'name', 'options' => [], 'selected' => null, 'required' => false])
<div>
    <label for="{{ $name }}" class="block text-xs font-bold uppercase tracking-wide text-muted mb-2">{{ $label }}</label>
    <select id="{{ $name }}" name="{{ $name }}" @if ($required) required @endif
            {{ $attributes->merge(['class' => 'w-full rounded-xl border border-border bg-panel-2 px-3.5 py-2.5 text-text outline-none focus:border-blue focus:ring-2 focus:ring-blue/30']) }}>
        @foreach ($options as $val => $text)
            <option value="{{ $val }}" @selected((string) old($name, $selected) === (string) $val)>{{ $text }}</option>
        @endforeach
    </select>
    @error($name)
        <p class="mt-1.5 text-sm" style="color: var(--red);">{{ $message }}</p>
    @enderror
</div>
