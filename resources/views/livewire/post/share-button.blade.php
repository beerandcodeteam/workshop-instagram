<div
    x-data="{
        copied: false,
        async copy(url) {
            try {
                await navigator.clipboard.writeText(url);
            } catch (e) {}
            this.copied = true;
            setTimeout(() => { this.copied = false; }, 1500);
        },
    }"
    @post.shared.window="copy($event.detail.url)"
    class="inline-flex"
>
    <button
        type="button"
        wire:click="share"
        class="inline-flex items-center gap-1 text-sm font-medium text-[var(--color-text)] hover:text-[var(--color-brand-via)] transition focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)] rounded-[var(--radius-full)]"
        aria-label="Compartilhar"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            class="h-5 w-5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M4 12v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7" />
            <polyline points="16 6 12 2 8 6" />
            <line x1="12" y1="2" x2="12" y2="15" />
        </svg>
        <span x-show="! copied">Compartilhar</span>
        <span x-show="copied" x-cloak>Link copiado!</span>
    </button>
</div>
