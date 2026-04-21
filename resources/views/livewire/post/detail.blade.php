<div>
    <div
        x-data="{ show: @entangle('open').live, close() { $wire.closeModal() } }"
        x-show="show"
        x-cloak
        @keydown.escape.window="close()"
        style="display: none;"
    >
        <template x-teleport="body">
            <div
                x-show="show"
                x-transition.opacity
                class="fixed inset-0 z-50 flex items-center justify-center p-0 md:p-6"
                role="dialog"
                aria-modal="true"
            >
                <div
                    x-show="show"
                    x-transition.opacity
                    class="fixed inset-0 bg-black/80"
                    @click="close()"
                    aria-hidden="true"
                ></div>

                <button
                    type="button"
                    @click="close()"
                    class="fixed top-4 right-4 z-[60] inline-flex h-9 w-9 items-center justify-center rounded-[var(--radius-full)] bg-transparent text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white"
                    aria-label="Fechar"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                @if ($post)
                    @php($slug = $post->type->slug)
                    @php($urls = $this->mediaUrls)

                    <div
                        x-show="show"
                        x-transition
                        @click.stop
                        class="relative z-10 flex w-full max-w-6xl h-full md:h-[85vh] bg-[var(--color-surface)] overflow-hidden md:rounded-[var(--radius-lg)] shadow-2xl flex-col md:flex-row"
                    >
                        <div class="flex-1 min-h-[320px] md:min-h-0 bg-black flex items-center justify-center overflow-hidden">
                            @if ($slug === 'text')
                                <div class="p-8 text-base leading-relaxed whitespace-pre-line text-white max-w-prose">
                                    {{ $post->body }}
                                </div>
                            @elseif ($slug === 'image' && count($urls) > 0)
                                @if (count($urls) === 1)
                                    <img
                                        src="{{ $urls[0] }}"
                                        alt="Imagem da publicação de {{ $post->author->name }}"
                                        class="max-h-full max-w-full object-contain"
                                    />
                                @else
                                    <div
                                        x-data="{ index: 0, total: {{ count($urls) }}, next() { this.index = (this.index + 1) % this.total }, prev() { this.index = (this.index - 1 + this.total) % this.total } }"
                                        class="relative h-full w-full flex items-center justify-center"
                                    >
                                        @foreach ($urls as $i => $url)
                                            <img
                                                src="{{ $url }}"
                                                alt="Imagem {{ $i + 1 }} da publicação de {{ $post->author->name }}"
                                                class="max-h-full max-w-full object-contain"
                                                x-show="index === {{ $i }}"
                                                @if ($i !== 0) x-cloak @endif
                                            />
                                        @endforeach

                                        <button
                                            type="button"
                                            @click="prev()"
                                            class="absolute left-3 top-1/2 -translate-y-1/2 inline-flex h-8 w-8 items-center justify-center rounded-[var(--radius-full)] bg-white/80 text-[var(--color-text)] hover:bg-white focus:outline-none"
                                            aria-label="Imagem anterior"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
                                        </button>
                                        <button
                                            type="button"
                                            @click="next()"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 inline-flex h-8 w-8 items-center justify-center rounded-[var(--radius-full)] bg-white/80 text-[var(--color-text)] hover:bg-white focus:outline-none"
                                            aria-label="Próxima imagem"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6" /></svg>
                                        </button>
                                    </div>
                                @endif
                            @elseif ($slug === 'video' && count($urls) > 0)
                                <video
                                    controls
                                    preload="metadata"
                                    src="{{ $urls[0] }}"
                                    class="max-h-full max-w-full"
                                >
                                    Seu navegador não suporta a reprodução de vídeo.
                                </video>
                            @endif
                        </div>

                        <div class="flex w-full md:w-[400px] shrink-0 flex-col border-t md:border-t-0 md:border-l border-[var(--color-border)] bg-[var(--color-surface)]">
                            <div class="flex items-center gap-3 px-4 py-3 border-b border-[var(--color-border)]">
                                <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-sm font-semibold text-white">
                                    {{ strtoupper(mb_substr($post->author->name ?? 'U', 0, 1)) }}
                                </div>
                                <p class="truncate text-sm font-semibold text-[var(--color-text)]">
                                    {{ $post->author->name }}
                                </p>
                            </div>

                            <div class="flex-1 overflow-y-auto px-4 py-3 space-y-4">
                                @if (filled($post->body) && in_array($slug, ['image', 'video'], true))
                                    <div class="flex items-start gap-3 text-sm">
                                        <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-xs font-semibold text-white">
                                            {{ strtoupper(mb_substr($post->author->name ?? 'U', 0, 1)) }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <span class="font-semibold text-[var(--color-text)]">{{ $post->author->name }}</span>
                                            <span class="ml-1 whitespace-pre-line text-[var(--color-text)]">{{ $post->body }}</span>
                                            <div class="mt-0.5 text-xs text-[var(--color-text-muted)]" title="{{ $post->created_at?->format('d/m/Y H:i') }}">
                                                {{ $post->created_at?->diffForHumans() }}
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @forelse ($this->comments as $comment)
                                    <div class="flex items-start gap-3 text-sm">
                                        <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-xs font-semibold text-white">
                                            {{ strtoupper(mb_substr($comment->author->name ?? 'U', 0, 1)) }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <span class="font-semibold text-[var(--color-text)]">{{ $comment->author->name }}</span>
                                            <span class="ml-1 whitespace-pre-line text-[var(--color-text)]">{{ $comment->body }}</span>
                                            <div class="mt-0.5 text-xs text-[var(--color-text-muted)]" title="{{ $comment->created_at?->format('d/m/Y H:i') }}">
                                                {{ $comment->created_at?->diffForHumans() }}
                                            </div>
                                        </div>

                                        @if (auth()->check() && auth()->id() === $comment->user_id)
                                            <button
                                                type="button"
                                                wire:click="deleteComment({{ $comment->id }})"
                                                wire:confirm="Excluir este comentário?"
                                                class="shrink-0 text-xs text-[var(--color-text-muted)] hover:text-[var(--color-danger)] focus:outline-none focus:text-[var(--color-danger)]"
                                                aria-label="Excluir comentário"
                                            >
                                                Excluir
                                            </button>
                                        @endif
                                    </div>
                                @empty
                                    @if (blank($post->body))
                                        <p class="text-sm text-[var(--color-text-muted)]">Nenhum comentário ainda. Seja o primeiro.</p>
                                    @endif
                                @endforelse
                            </div>

                            <div class="border-t border-[var(--color-border)] px-4 py-3">
                                <p class="text-sm font-semibold text-[var(--color-text)]">
                                    {{ $post->likes_count }} {{ $post->likes_count === 1 ? 'curtida' : 'curtidas' }}
                                </p>
                                <p class="mt-0.5 text-xs uppercase tracking-wide text-[var(--color-text-muted)]" title="{{ $post->created_at?->format('d/m/Y H:i') }}">
                                    {{ $post->created_at?->diffForHumans() }}
                                </p>
                            </div>

                            @auth
                                <form wire:submit="addComment" class="flex items-center gap-2 border-t border-[var(--color-border)] px-4 py-3">
                                    <div class="flex-1">
                                        <input
                                            type="text"
                                            wire:model.live.debounce.150ms="form.body"
                                            placeholder="Adicione um comentário..."
                                            aria-label="Novo comentário"
                                            class="w-full bg-transparent text-sm text-[var(--color-text)] placeholder-[var(--color-text-muted)] focus:outline-none"
                                        />
                                        @error('form.body')
                                            <p class="mt-1 text-xs text-[var(--color-danger)]">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <button
                                        type="submit"
                                        class="text-sm font-semibold text-[var(--color-brand-via)] hover:opacity-80 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                        @disabled(trim($form->body) === '')
                                    >
                                        <span wire:loading.remove wire:target="addComment">Publicar</span>
                                        <span wire:loading wire:target="addComment">Publicando...</span>
                                    </button>
                                </form>
                            @else
                                <div class="border-t border-[var(--color-border)] px-4 py-3 text-sm text-[var(--color-text-muted)]">
                                    <a href="{{ url('/login') }}" class="font-semibold text-[var(--color-brand-via)] hover:underline" wire:navigate>Entre</a>
                                    para comentar.
                                </div>
                            @endauth
                        </div>
                    </div>
                @endif
            </div>
        </template>
    </div>
</div>
