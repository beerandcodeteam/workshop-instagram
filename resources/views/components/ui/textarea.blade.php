@props([
    'label' => null,
    'id' => null,
    'error' => null,
    'hint' => null,
    'counter' => null,
    'required' => false,
    'rows' => 4,
])

@php
    $textareaId = $id ?? ('textarea-'.str()->random(6));
    $hasError = ! empty($error) || ! empty($errors?->first($attributes->get('name') ?? ''));
    $errorMessage = $error ?? ($errors?->first($attributes->get('name') ?? '') ?? null);

    $textareaClasses = 'block w-full px-3 py-2 text-sm bg-[var(--color-surface)] text-[var(--color-text)] border rounded-[var(--radius-md)] placeholder:text-[var(--color-text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)] focus:border-transparent transition resize-y';
    $textareaClasses .= $hasError
        ? ' border-[var(--color-danger)] focus:ring-[var(--color-danger)]'
        : ' border-[var(--color-border)]';
@endphp

<div class="flex flex-col gap-1">
    @if ($label)
        <label for="{{ $textareaId }}" class="text-sm font-medium text-[var(--color-text)]">
            {{ $label }}
            @if ($required)
                <span class="text-[var(--color-danger)]">*</span>
            @endif
        </label>
    @endif

    <textarea
        id="{{ $textareaId }}"
        rows="{{ $rows }}"
        @if ($required) required @endif
        {{ $attributes->merge(['class' => $textareaClasses]) }}
    >{{ $slot }}</textarea>

    <div class="flex items-start justify-between gap-2">
        <div class="flex-1">
            @if ($hasError)
                <p class="text-xs text-[var(--color-danger)]">{{ $errorMessage }}</p>
            @elseif ($hint)
                <p class="text-xs text-[var(--color-text-muted)]">{{ $hint }}</p>
            @endif
        </div>

        @if ($counter)
            <p class="text-xs text-[var(--color-text-muted)]">{{ $counter }}</p>
        @endif
    </div>
</div>
