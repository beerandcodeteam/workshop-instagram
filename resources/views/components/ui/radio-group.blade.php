@props([
    'label' => null,
    'error' => null,
    'hint' => null,
    'inline' => false,
])

@php
    $hasError = ! empty($error);
    $layoutClasses = $inline ? 'flex flex-wrap gap-4' : 'flex flex-col gap-2';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-2']) }} role="radiogroup">
    @if ($label)
        <span class="text-sm font-medium text-[var(--color-text)]">{{ $label }}</span>
    @endif

    <div class="{{ $layoutClasses }}">
        {{ $slot }}
    </div>

    @if ($hasError)
        <p class="text-xs text-[var(--color-danger)]">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-xs text-[var(--color-text-muted)]">{{ $hint }}</p>
    @endif
</div>
