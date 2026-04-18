<button
    type="button"
    wire:click="toggle"
    @class([
        'inline-flex items-center gap-1 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)] rounded-[var(--radius-full)]',
        'text-[var(--color-danger)]' => $isLiked,
        'text-[var(--color-text)] hover:text-[var(--color-danger)]' => ! $isLiked,
    ])
    aria-label="{{ $isLiked ? 'Descurtir' : 'Curtir' }}"
    aria-pressed="{{ $isLiked ? 'true' : 'false' }}"
>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        class="h-5 w-5"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        fill="{{ $isLiked ? 'currentColor' : 'none' }}"
        aria-hidden="true"
    >
        <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z" />
    </svg>
    <span>{{ $likesCount }}</span>
</button>
