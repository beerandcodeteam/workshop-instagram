@props([
    'label' => null,
    'id' => null,
    'checked' => false,
    'value' => null,
])

@php
    $radioId = $id ?? ('radio-'.str()->random(6));
    $classes = 'h-4 w-4 border border-[var(--color-border)] text-[var(--color-brand-via)] focus:ring-2 focus:ring-[var(--color-brand-via)] focus:ring-offset-0 transition';
@endphp

<label for="{{ $radioId }}" class="inline-flex items-center gap-2 cursor-pointer select-none">
    <input
        id="{{ $radioId }}"
        type="radio"
        @if ($value !== null) value="{{ $value }}" @endif
        @checked($checked)
        {{ $attributes->merge(['class' => $classes]) }}
    />
    @if ($label)
        <span class="text-sm text-[var(--color-text)]">{{ $label }}</span>
    @elseif (trim((string) $slot) !== '')
        <span class="text-sm text-[var(--color-text)]">{{ $slot }}</span>
    @endif
</label>
