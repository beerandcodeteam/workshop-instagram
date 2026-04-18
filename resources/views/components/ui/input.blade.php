@props([
    'label' => null,
    'id' => null,
    'type' => 'text',
    'error' => null,
    'hint' => null,
    'required' => false,
])

@php
    $inputId = $id ?? ('input-'.str()->random(6));
    $hasError = ! empty($error) || ! empty($errors?->first($attributes->get('name') ?? ''));
    $errorMessage = $error ?? ($errors?->first($attributes->get('name') ?? '') ?? null);

    $inputClasses = 'block w-full px-3 py-2 text-sm bg-[var(--color-surface)] text-[var(--color-text)] border rounded-[var(--radius-md)] placeholder:text-[var(--color-text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)] focus:border-transparent transition';
    $inputClasses .= $hasError
        ? ' border-[var(--color-danger)] focus:ring-[var(--color-danger)]'
        : ' border-[var(--color-border)]';
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <label for="{{ $inputId }}" class="text-sm font-medium text-[var(--color-text)]">
            {{ $label }}
            @if ($required)
                <span class="text-[var(--color-danger)]">*</span>
            @endif
        </label>
    @endif

    <input
        id="{{ $inputId }}"
        type="{{ $type }}"
        @if ($required) required @endif
        {{ $attributes->merge(['class' => $inputClasses]) }}
    />

    @if ($hasError)
        <p class="text-xs text-[var(--color-danger)]">{{ $errorMessage }}</p>
    @elseif ($hint)
        <p class="text-xs text-[var(--color-text-muted)]">{{ $hint }}</p>
    @endif
</div>
