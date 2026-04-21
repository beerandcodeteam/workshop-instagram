<div class="flex gap-12 justify-center">
    <div class="w-full max-w-[470px] space-y-4">
        <livewire:feed.stories />

        <div class="space-y-6">
            @forelse ($posts as $post)
                <livewire:post.card :post="$post" :key="'post-'.$post->id" />
            @empty
                <div class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-6 py-12 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-10 w-10 text-[var(--color-text-muted)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="3" y="3" width="18" height="18" rx="3" />
                        <circle cx="8.5" cy="8.5" r="1.5" />
                        <path d="M21 15l-5-5L5 21" />
                    </svg>
                    <h2 class="mt-4 text-base font-semibold text-[var(--color-text)]">Ainda não há publicações no feed</h2>
                    <p class="mt-1 text-sm text-[var(--color-text-muted)]">
                        Seja o primeiro a compartilhar um momento.
                    </p>
                    <div class="mt-4">
                        <x-ui.button
                            variant="primary"
                            size="sm"
                            onclick="Livewire.dispatch('open-create-post-modal')"
                        >
                            Criar publicação
                        </x-ui.button>
                    </div>
                </div>
            @endforelse

            @if ($hasMorePages)
                <div
                    x-data="{
                        observe() {
                            const observer = new IntersectionObserver((entries) => {
                                entries.forEach((entry) => {
                                    if (entry.isIntersecting) {
                                        $wire.loadMore();
                                    }
                                });
                            }, { rootMargin: '200px' });

                            observer.observe($el);
                        },
                    }"
                    x-init="observe()"
                    wire:key="feed-load-more-{{ $posts->count() }}"
                    class="flex items-center justify-center py-6 text-sm text-[var(--color-text-muted)]"
                >
                    <button
                        type="button"
                        wire:click="loadMore"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2 text-sm font-medium text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="loadMore">Carregar mais</span>
                        <span wire:loading wire:target="loadMore">Carregando...</span>
                    </button>
                </div>
            @endif
        </div>
    </div>

    <div class="hidden xl:block w-[320px] pt-4">
        <div class="sticky top-6">
            <livewire:feed.suggestions />
        </div>
    </div>
</div>
