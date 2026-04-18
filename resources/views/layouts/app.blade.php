<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Instagram') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[var(--color-bg)] font-sans text-[var(--color-text)] antialiased">
    <div class="min-h-screen flex flex-col">
        <header class="sticky top-0 z-40 bg-[var(--color-surface)]/90 backdrop-blur border-b border-[var(--color-border)]">
            <div class="max-w-5xl mx-auto flex items-center justify-between px-4 h-14">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-2 text-lg font-bold bg-gradient-to-r from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] bg-clip-text text-transparent" wire:navigate>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="url(#logo-gradient-app)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <defs>
                            <linearGradient id="logo-gradient-app" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#F58529" />
                                <stop offset="50%" stop-color="#DD2A7B" />
                                <stop offset="100%" stop-color="#8134AF" />
                            </linearGradient>
                        </defs>
                        <rect x="3" y="3" width="18" height="18" rx="5" />
                        <circle cx="12" cy="12" r="4" />
                        <circle cx="17.5" cy="6.5" r="1" fill="currentColor" />
                    </svg>
                    <span class="hidden sm:inline">{{ config('app.name', 'Instagram') }}</span>
                </a>

                <div class="flex items-center gap-2">
                    @auth
                        <x-ui.button as="a" href="{{ url('/posts/create') }}" size="sm" variant="primary" wire:navigate>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                            <span class="hidden sm:inline">Criar post</span>
                        </x-ui.button>

                        <div
                            x-data="{ open: false }"
                            @click.outside="open = false"
                            @keydown.escape.window="open = false"
                            class="relative"
                        >
                            <button
                                type="button"
                                @click="open = !open"
                                class="inline-flex items-center justify-center h-9 w-9 rounded-[var(--radius-full)] bg-gradient-to-br from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] text-white font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)] focus:ring-offset-2 focus:ring-offset-[var(--color-surface)]"
                                aria-haspopup="true"
                                :aria-expanded="open.toString()"
                                aria-label="Menu do usuário"
                            >
                                {{ strtoupper(mb_substr(auth()->user()->name ?? 'U', 0, 1)) }}
                            </button>

                            <div
                                x-show="open"
                                x-transition
                                x-cloak
                                class="absolute right-0 mt-2 w-56 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-[var(--radius-md)] shadow-lg py-1 z-50"
                                role="menu"
                            >
                                <div class="px-4 py-2 border-b border-[var(--color-border)]">
                                    <p class="text-sm font-medium text-[var(--color-text)] truncate">{{ auth()->user()->name ?? '' }}</p>
                                    <p class="text-xs text-[var(--color-text-muted)] truncate">{{ auth()->user()->email ?? '' }}</p>
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
                            </div>
                        </div>
                    @else
                        <x-ui.button as="a" href="{{ url('/login') }}" size="sm" variant="secondary" wire:navigate>
                            Entrar
                        </x-ui.button>
                    @endauth
                </div>
            </div>
        </header>

        <main class="flex-1 w-full">
            <div class="mx-auto w-full max-w-[630px] px-4 py-6">
                @session('status')
                    <div class="mb-4 rounded-[var(--radius-md)] bg-[var(--color-success)]/10 border border-[var(--color-success)]/30 text-[var(--color-success)] px-4 py-2 text-sm">
                        {{ session('status') }}
                    </div>
                @endsession

                @session('error')
                    <div class="mb-4 rounded-[var(--radius-md)] bg-[var(--color-danger)]/10 border border-[var(--color-danger)]/30 text-[var(--color-danger)] px-4 py-2 text-sm">
                        {{ session('error') }}
                    </div>
                @endsession

                {{ $slot }}
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
