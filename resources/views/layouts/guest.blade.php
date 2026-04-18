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
<body class="min-h-screen font-sans text-[var(--color-text)] antialiased">
    <div class="relative min-h-screen flex flex-col items-center justify-center px-4 py-10 overflow-hidden">
        <div class="absolute inset-0 -z-10 bg-[var(--color-bg)]"></div>
        <div
            class="absolute inset-0 -z-10 opacity-30 blur-3xl"
            style="background: radial-gradient(circle at 20% 20%, var(--color-brand-from), transparent 45%),
                               radial-gradient(circle at 80% 30%, var(--color-brand-via), transparent 50%),
                               radial-gradient(circle at 50% 90%, var(--color-brand-to), transparent 55%);"
            aria-hidden="true"
        ></div>

        <a href="{{ url('/') }}" class="mb-8 inline-flex items-center gap-2 text-2xl font-bold bg-gradient-to-r from-[var(--color-brand-from)] via-[var(--color-brand-via)] to-[var(--color-brand-to)] bg-clip-text text-transparent">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="url(#logo-gradient)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <defs>
                    <linearGradient id="logo-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#F58529" />
                        <stop offset="50%" stop-color="#DD2A7B" />
                        <stop offset="100%" stop-color="#8134AF" />
                    </linearGradient>
                </defs>
                <rect x="3" y="3" width="18" height="18" rx="5" />
                <circle cx="12" cy="12" r="4" />
                <circle cx="17.5" cy="6.5" r="1" fill="currentColor" />
            </svg>
            <span>{{ config('app.name', 'Instagram') }}</span>
        </a>

        <main class="w-full max-w-md">
            <div class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-[var(--radius-lg)] shadow-sm p-6 sm:p-8">
                @session('status')
                    <div class="mb-4 rounded-[var(--radius-md)] bg-[var(--color-success)]/10 border border-[var(--color-success)]/30 text-[var(--color-success)] px-4 py-2 text-sm">
                        {{ session('status') }}
                    </div>
                @endsession

                {{ $slot }}
            </div>

            @isset($footer)
                <div class="mt-4 text-center text-sm text-[var(--color-text-muted)]">
                    {{ $footer }}
                </div>
            @endisset
        </main>
    </div>

    @livewireScripts
</body>
</html>
