<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Instagram') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--color-bg)] font-sans text-[var(--color-text)] antialiased">
    <main class="mx-auto max-w-[630px] px-4 py-12 text-center text-[var(--color-text-muted)]">
        <p class="text-sm">Feed em breve.</p>
        <form method="POST" action="{{ url('/logout') }}" class="mt-6 inline-block">
            @csrf
            <button type="submit" class="text-sm text-[var(--color-brand-via)] hover:underline">Sair</button>
        </form>
    </main>
</body>
</html>
