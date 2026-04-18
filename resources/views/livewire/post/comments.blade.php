<div class="border-t border-[var(--color-border)] px-4 py-3">
    @if ($comments->isEmpty())
        <p class="text-sm text-[var(--color-text-muted)]">
            Nenhum comentário ainda. Seja o primeiro.
        </p>
    @else
        <ul class="space-y-2">
            @foreach ($comments as $comment)
                <li class="flex items-start justify-between gap-3 text-sm">
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
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addComment" class="mt-3 flex items-start gap-2">
        <div class="flex-1">
            <x-ui.input
                type="text"
                wire:model="form.body"
                placeholder="Adicione um comentário..."
                aria-label="Novo comentário"
            />
            @error('form.body')
                <p class="mt-1 text-xs text-[var(--color-danger)]">{{ $message }}</p>
            @enderror
        </div>
        <x-ui.button type="submit" variant="primary" size="sm">
            <span wire:loading.remove wire:target="addComment">Publicar</span>
            <span wire:loading wire:target="addComment">Publicando...</span>
        </x-ui.button>
    </form>
</div>
