@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'loading' => false,
    'as' => 'button',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 font-semibold transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-[var(--color-surface)] disabled:opacity-60 disabled:cursor-not-allowed';

    $variants = [
        'primary' => 'text-white bg-gradient-to-r from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] hover:brightness-110 focus:ring-[var(--color-brand-via)]',
        'secondary' => 'border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] focus:ring-[var(--color-neutral-500)]',
        'ghost' => 'bg-transparent text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] focus:ring-[var(--color-neutral-300)]',
        'danger' => 'text-white bg-[var(--color-danger)] hover:brightness-110 focus:ring-[var(--color-danger)]',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-sm rounded-[var(--radius-sm)]',
        'md' => 'px-4 py-2 text-sm rounded-[var(--radius-md)]',
        'lg' => 'px-6 py-3 text-base rounded-[var(--radius-lg)]',
    ];

    $classes = $base . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']);
@endphp

@if ($as === 'a')
    <a {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)
            <svg class="animate-spin h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
        @endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @if ($loading) disabled @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($loading)
            <svg class="animate-spin h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
        @endif
        {{ $slot }}
    </button>
@endif
