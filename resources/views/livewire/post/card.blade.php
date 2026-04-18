<article class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] overflow-hidden">
    <header class="flex items-center gap-3 px-4 py-3">
        <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-sm font-semibold text-white">
            {{ strtoupper(mb_substr($post->author->name ?? 'U', 0, 1)) }}
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-[var(--color-text)]">
                {{ $post->author->name }}
            </p>
            <p class="text-xs text-[var(--color-text-muted)]" title="{{ $post->created_at?->format('d/m/Y H:i') }}">
                {{ $post->created_at?->diffForHumans() }}
            </p>
        </div>
    </header>

    @php($slug = $post->type->slug)

    @if ($slug === 'text')
        <div class="px-4 pb-4 text-sm leading-relaxed whitespace-pre-line text-[var(--color-text)]">
            {{ $post->body }}
        </div>
    @elseif ($slug === 'image')
        @php($urls = $this->mediaUrls)
        @if (count($urls) === 1)
            <div class="bg-[var(--color-neutral-100)]">
                <img
                    src="{{ $urls[0] }}"
                    alt="Imagem da publicação de {{ $post->author->name }}"
                    class="block w-full max-h-[600px] object-contain"
                />
            </div>
        @else
            <div
                x-data="{
                    index: 0,
                    total: {{ count($urls) }},
                    next() { this.index = (this.index + 1) % this.total },
                    prev() { this.index = (this.index - 1 + this.total) % this.total },
                }"
                class="relative bg-[var(--color-neutral-100)]"
            >
                <div class="relative">
                    @foreach ($urls as $i => $url)
                        <img
                            src="{{ $url }}"
                            alt="Imagem {{ $i + 1 }} da publicação de {{ $post->author->name }}"
                            class="block w-full max-h-[600px] object-contain"
                            x-show="index === {{ $i }}"
                            @if ($i !== 0) x-cloak @endif
                        />
                    @endforeach
                </div>

                <button
                    type="button"
                    @click="prev()"
                    class="absolute left-2 top-1/2 -translate-y-1/2 inline-flex h-8 w-8 items-center justify-center rounded-[var(--radius-full)] bg-[var(--color-surface)]/80 text-[var(--color-text)] hover:bg-[var(--color-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)]"
                    aria-label="Imagem anterior"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 18l-6-6 6-6" />
                    </svg>
                </button>

                <button
                    type="button"
                    @click="next()"
                    class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex h-8 w-8 items-center justify-center rounded-[var(--radius-full)] bg-[var(--color-surface)]/80 text-[var(--color-text)] hover:bg-[var(--color-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)]"
                    aria-label="Próxima imagem"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 6l6 6-6 6" />
                    </svg>
                </button>

                <div class="absolute bottom-2 left-1/2 -translate-x-1/2 flex items-center gap-1.5">
                    @foreach ($urls as $i => $url)
                        <span
                            class="h-1.5 w-1.5 rounded-[var(--radius-full)] transition"
                            :class="index === {{ $i }} ? 'bg-[var(--color-brand-via)]' : 'bg-[var(--color-neutral-300)]'"
                        ></span>
                    @endforeach
                </div>
            </div>
        @endif
    @elseif ($slug === 'video')
        @php($urls = $this->mediaUrls)
        @if (! empty($urls))
            <div class="bg-black">
                <video
                    controls
                    preload="metadata"
                    src="{{ $urls[0] }}"
                    class="block w-full max-h-[600px]"
                >
                    Seu navegador não suporta a reprodução de vídeo.
                </video>
            </div>
        @endif
    @endif

    <div class="flex items-center gap-4 px-4 py-3">
        <button
            type="button"
            class="inline-flex items-center gap-1 text-sm font-medium text-[var(--color-text)] hover:text-[var(--color-danger)] transition"
            aria-label="Curtir"
            disabled
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z" />
            </svg>
            <span>{{ $post->likes_count ?? $post->likes->count() }}</span>
        </button>

        <button
            type="button"
            wire:click="toggleComments"
            class="inline-flex items-center gap-1 text-sm font-medium text-[var(--color-text)] hover:text-[var(--color-brand-via)] transition"
            aria-label="Comentários"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" />
            </svg>
            <span>{{ $post->comments_count ?? $post->comments->count() }}</span>
        </button>
    </div>

    @if (in_array($slug, ['image', 'video'], true) && filled($post->body))
        <div class="px-4 pb-4 text-sm leading-relaxed text-[var(--color-text)]">
            <span class="font-semibold">{{ $post->author->name }}</span>
            <span class="ml-1 whitespace-pre-line">{{ $post->body }}</span>
        </div>
    @endif

    @if ($showComments)
        <div class="border-t border-[var(--color-border)] px-4 py-3 text-sm text-[var(--color-text-muted)]">
            Comentários em breve.
        </div>
    @endif
</article>
