<div class="rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3">
    <ul class="flex items-start gap-4 overflow-x-auto pb-1">
        @foreach ($users as $user)
            <li class="flex flex-col items-center gap-1.5 w-16 shrink-0">
                <div class="p-[2px] rounded-[var(--radius-full)] bg-gradient-to-tr from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)]">
                    <div class="p-[2px] bg-[var(--color-surface)] rounded-[var(--radius-full)]">
                        <div class="inline-flex h-14 w-14 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-base font-semibold text-white">
                            {{ strtoupper(mb_substr($user->name ?? 'U', 0, 1)) }}
                        </div>
                    </div>
                </div>
                <span class="w-full truncate text-center text-xs text-[var(--color-text)]" title="{{ $user->name }}">
                    {{ auth()->id() === $user->id ? 'Seu story' : str($user->name)->explode(' ')->first() }}
                </span>
            </li>
        @endforeach
    </ul>
</div>
