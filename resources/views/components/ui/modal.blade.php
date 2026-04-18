@props([
    'name' => null,
    'title' => null,
    'maxWidth' => 'md',
])

@php
    $maxWidthClass = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-xl',
        '2xl' => 'max-w-2xl',
    ][$maxWidth] ?? 'max-w-md';
@endphp

<div
    @if ($name)
        x-data="{ show: false, init() { window.addEventListener('open-modal', (e) => { if (e.detail === '{{ $name }}') this.show = true }); window.addEventListener('close-modal', (e) => { if (e.detail === '{{ $name }}') this.show = false }) }, close() { this.show = false } }"
    @else
        x-data="{ show: @entangle($attributes->wire('model')).live, close() { this.show = false } }"
    @endif
    x-show="show"
    x-cloak
    @keydown.escape.window="close()"
    style="display: none;"
>
    <template x-teleport="body">
        <div
            x-show="show"
            x-transition.opacity
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
        >
            <div
                x-show="show"
                x-transition.opacity
                class="fixed inset-0 bg-black/60"
                @click="close()"
                aria-hidden="true"
            ></div>

            <div
                x-show="show"
                x-transition
                @click.stop
                class="relative w-full {{ $maxWidthClass }} bg-[var(--color-surface)] rounded-[var(--radius-lg)] shadow-xl border border-[var(--color-border)]"
            >
                @if ($title || isset($header))
                    <div class="flex items-center justify-between px-5 py-4 border-b border-[var(--color-border)]">
                        <h2 class="text-base font-semibold text-[var(--color-text)]">
                            {{ $header ?? $title }}
                        </h2>
                        <button
                            type="button"
                            @click="close()"
                            class="text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition"
                            aria-label="Fechar"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                @endif

                <div class="px-5 py-4">
                    {{ $slot }}
                </div>

                @isset($footer)
                    <div class="px-5 py-4 border-t border-[var(--color-border)] flex items-center justify-end gap-2">
                        {{ $footer }}
                    </div>
                @endisset
            </div>
        </div>
    </template>
</div>
