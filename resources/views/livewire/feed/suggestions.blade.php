<aside class="w-full space-y-6">
    @if ($currentUser)
        <div class="flex items-center gap-3">
            <div class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-lg font-semibold text-white">
                {{ strtoupper(mb_substr($currentUser->name ?? 'U', 0, 1)) }}
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-[var(--color-text)]">{{ str($currentUser->name)->explode(' ')->first() }}</p>
                <p class="truncate text-sm text-[var(--color-text-muted)]">{{ $currentUser->name }}</p>
            </div>
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <button type="submit" class="text-xs font-semibold text-[var(--color-brand-via)] hover:opacity-75 transition">
                    Sair
                </button>
            </form>
        </div>
    @endif

    <div>
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-[var(--color-text-muted)]">Sugestões para você</p>
            <button type="button" class="text-xs font-semibold text-[var(--color-text)] hover:text-[var(--color-text-muted)] transition">
                Ver tudo
            </button>
        </div>

        <ul class="mt-4 space-y-3">
            @forelse ($suggestions as $user)
                <li class="flex items-center gap-3">
                    <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-xs font-semibold text-white">
                        {{ strtoupper(mb_substr($user->name ?? 'U', 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-[var(--color-text)]">{{ str($user->name)->explode(' ')->first() }}</p>
                        <p class="truncate text-xs text-[var(--color-text-muted)]">Sugestão para você</p>
                    </div>
                    <button type="button" class="text-xs font-semibold text-[var(--color-brand-via)] hover:text-[var(--color-text)] transition">
                        Seguir
                    </button>
                </li>
            @empty
                <li class="text-sm text-[var(--color-text-muted)]">Sem sugestões no momento.</li>
            @endforelse
        </ul>
    </div>

    <p class="text-xs text-[var(--color-text-muted)] leading-relaxed">
        Sobre &middot; Ajuda &middot; Imprensa &middot; API &middot; Carreiras &middot; Privacidade &middot; Termos &middot; Localizações &middot; Idioma &middot; Meta&nbsp;Verified
    </p>
    <p class="text-xs uppercase tracking-wider text-[var(--color-text-muted)]">
        &copy; {{ date('Y') }} {{ config('app.name', 'Instagram') }} do workshop
    </p>
</aside>
