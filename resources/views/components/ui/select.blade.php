@props([
    'label' => null,
    'id' => null,
    'error' => null,
    'hint' => null,
    'required' => false,
])

@php
    $selectId = $id ?? ('select-'.str()->random(6));
    $hasError = ! empty($error) || ! empty($errors?->first($attributes->get('name') ?? ''));
    $errorMessage = $error ?? ($errors?->first($attributes->get('name') ?? '') ?? null);

    $selectClasses = 'block w-full px-3 py-2 text-sm bg-[var(--color-surface)] text-[var(--color-text)] border rounded-[var(--radius-md)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)] focus:border-transparent transition appearance-none bg-[right_0.75rem_center] bg-no-repeat pr-10';
    $selectClasses .= $hasError
        ? ' border-[var(--color-danger)] focus:ring-[var(--color-danger)]'
        : ' border-[var(--color-border)]';
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <label for="{{ $selectId }}" class="text-sm font-medium text-[var(--color-text)]">
            {{ $label }}
            @if ($required)
                <span class="text-[var(--color-danger)]">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        <select
            id="{{ $selectId }}"
            @if ($required) required @endif
            {{ $attributes->merge(['class' => $selectClasses]) }}
        >
            {{ $slot }}
        </select>
        <svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--color-text-muted)]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    </div>

    @if ($hasError)
        <p class="text-xs text-[var(--color-danger)]">{{ $errorMessage }}</p>
    @elseif ($hint)
        <p class="text-xs text-[var(--color-text-muted)]">{{ $hint }}</p>
    @endif
</div>
