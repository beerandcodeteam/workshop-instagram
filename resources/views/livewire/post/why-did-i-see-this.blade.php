<div>
    <button
        type="button"
        wire:click="openModal"
        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] focus:outline-none focus:bg-[var(--color-neutral-100)]"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10" />
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
        </svg>
        <span>Por que vi isso?</span>
    </button>

    <x-ui.modal wire:model="open" title="Por que vi isso?" maxWidth="md">
        <div class="space-y-3 text-sm text-[var(--color-text)]">
            <p>{{ $this->reason }}</p>

            @if ($this->trace)
                <p class="text-xs text-[var(--color-text-muted)]">
                    Rastro gerado em {{ $this->trace->created_at?->diffForHumans() }}.
                </p>
            @endif
        </div>

        <div class="mt-4 flex items-center justify-end">
            <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeModal">
                Fechar
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
