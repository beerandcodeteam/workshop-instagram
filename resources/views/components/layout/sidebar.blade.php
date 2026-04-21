@props(['active' => 'home'])

@php
    $user = auth()->user();

    $itemClass = 'group flex items-center gap-4 rounded-[var(--radius-md)] px-3 py-3 text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] transition focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)]';
    $labelClass = 'hidden xl:inline text-base';
    $iconClass = 'h-6 w-6 shrink-0';
    $disabledTitle = 'Em breve';
@endphp

<aside class="hidden md:flex fixed top-0 left-0 z-30 h-screen w-[76px] xl:w-[245px] flex-col border-r border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-6">
    <a href="{{ url('/') }}" wire:navigate class="mb-8 flex items-center gap-3 px-3 h-10">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="url(#sidebar-logo-gradient)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <defs>
                <linearGradient id="sidebar-logo-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#F58529" />
                    <stop offset="50%" stop-color="#DD2A7B" />
                    <stop offset="100%" stop-color="#8134AF" />
                </linearGradient>
            </defs>
            <rect x="3" y="3" width="18" height="18" rx="5" />
            <circle cx="12" cy="12" r="4" />
            <circle cx="17.5" cy="6.5" r="1" fill="currentColor" />
        </svg>
        <span class="hidden xl:inline text-xl font-bold bg-gradient-to-r from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] bg-clip-text text-transparent">
            {{ config('app.name', 'Instagram') }}
        </span>
    </a>

    <nav class="flex-1 flex flex-col gap-1">
        <a href="{{ url('/') }}" wire:navigate @class([$itemClass, 'font-semibold bg-[var(--color-neutral-100)]' => $active === 'home'])>
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="{{ $active === 'home' ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 9.5L12 3l9 6.5V21a1 1 0 01-1 1h-5v-7h-6v7H4a1 1 0 01-1-1V9.5z" />
            </svg>
            <span class="{{ $labelClass }}">Página Inicial</span>
        </a>

        <button type="button" title="{{ $disabledTitle }}" @class([$itemClass, 'opacity-60 cursor-not-allowed'])>
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4.3-4.3" />
            </svg>
            <span class="{{ $labelClass }}">Pesquisa</span>
        </button>

        <button type="button" title="{{ $disabledTitle }}" @class([$itemClass, 'opacity-60 cursor-not-allowed'])>
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <polygon points="16 8 13 14 8 16 11 10 16 8" fill="currentColor" stroke="none" />
            </svg>
            <span class="{{ $labelClass }}">Explorar</span>
        </button>

        <button type="button" title="{{ $disabledTitle }}" @class([$itemClass, 'opacity-60 cursor-not-allowed'])>
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="4" width="18" height="16" rx="3" />
                <polygon points="10 9 16 12 10 15 10 9" fill="currentColor" stroke="none" />
            </svg>
            <span class="{{ $labelClass }}">Reels</span>
        </button>

        <button type="button" title="{{ $disabledTitle }}" @class([$itemClass, 'opacity-60 cursor-not-allowed'])>
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" />
            </svg>
            <span class="{{ $labelClass }}">Mensagens</span>
        </button>

        <button type="button" title="{{ $disabledTitle }}" @class([$itemClass, 'opacity-60 cursor-not-allowed'])>
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z" />
            </svg>
            <span class="{{ $labelClass }}">Notificações</span>
        </button>

        @auth
            <button
                type="button"
                onclick="Livewire.dispatch('open-create-post-modal')"
                class="{{ $itemClass }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="4" />
                    <path d="M12 8v8M8 12h8" />
                </svg>
                <span class="{{ $labelClass }}">Criar</span>
            </button>
        @endauth

        <button type="button" title="{{ $disabledTitle }}" @class([$itemClass, 'opacity-60 cursor-not-allowed'])>
            @auth
                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-[10px] font-semibold text-white">
                    {{ strtoupper(mb_substr($user->name ?? 'U', 0, 1)) }}
                </span>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21a8 8 0 0116 0" />
                </svg>
            @endauth
            <span class="{{ $labelClass }}">Perfil</span>
        </button>
    </nav>

    <div
        x-data="{ open: false }"
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        class="relative mt-2"
    >
        <button
            type="button"
            @click="open = ! open"
            class="{{ $itemClass }} w-full"
            :aria-expanded="open.toString()"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="3" y1="12" x2="21" y2="12" />
                <line x1="3" y1="6" x2="21" y2="6" />
                <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
            <span class="{{ $labelClass }}">Mais</span>
        </button>

        <div
            x-show="open"
            x-transition
            x-cloak
            class="absolute bottom-full mb-2 left-0 w-56 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] shadow-lg py-1 z-50"
            role="menu"
        >
            @auth
                <div class="px-4 py-2 border-b border-[var(--color-border)]">
                    <p class="text-sm font-medium text-[var(--color-text)] truncate">{{ $user->name }}</p>
                    <p class="text-xs text-[var(--color-text-muted)] truncate">{{ $user->email }}</p>
                </div>
                <form method="POST" action="{{ url('/logout') }}" role="none">
                    @csrf
                    <button
                        type="submit"
                        class="w-full text-left px-4 py-2 text-sm text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] transition"
                        role="menuitem"
                    >
                        Sair
                    </button>
                </form>
            @else
                <a href="{{ url('/login') }}" wire:navigate class="block px-4 py-2 text-sm text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] transition" role="menuitem">
                    Entrar
                </a>
            @endauth
        </div>
    </div>
</aside>
