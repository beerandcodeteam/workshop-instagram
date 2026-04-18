@props([
    'label' => null,
    'id' => null,
    'checked' => false,
])

@php
    $checkboxId = $id ?? ('checkbox-'.str()->random(6));
    $classes = 'h-4 w-4 rounded-[var(--radius-sm)] border border-[var(--color-border)] text-[var(--color-brand-via)] focus:ring-2 focus:ring-[var(--color-brand-via)] focus:ring-offset-0 transition';
@endphp

<label for="{{ $checkboxId }}" class="inline-flex items-center gap-2 cursor-pointer select-none">
    <input
        id="{{ $checkboxId }}"
        type="checkbox"
        @checked($checked)
        {{ $attributes->merge(['class' => $classes]) }}
    />
    @if ($label)
        <span class="text-sm text-[var(--color-text)]">{{ $label }}</span>
    @elseif (trim((string) $slot) !== '')
        <span class="text-sm text-[var(--color-text)]">{{ $slot }}</span>
    @endif
</label>
