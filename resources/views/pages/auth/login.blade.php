<div>
    <div class="mb-6 text-center">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">Entrar</h1>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">Acesse sua conta para continuar</p>
    </div>

    <form wire:submit="login" class="space-y-4">
        <x-ui.input
            label="E-mail"
            name="form.email"
            type="email"
            wire:model="form.email"
            :error="$errors->first('form.email')"
            required
            autofocus
            autocomplete="email"
        />

        <x-ui.input
            label="Senha"
            name="form.password"
            type="password"
            wire:model="form.password"
            :error="$errors->first('form.password')"
            required
            autocomplete="current-password"
        />

        <div class="flex items-center justify-between">
            <x-ui.checkbox
                label="Lembrar de mim"
                name="form.remember"
                wire:model="form.remember"
            />
        </div>

        <x-ui.button type="submit" variant="primary" class="w-full">
            Entrar
        </x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-[var(--color-text-muted)]">
        Ainda não tem uma conta?
        <a href="{{ url('/register') }}" class="font-medium text-[var(--color-brand-via)] hover:underline" wire:navigate>
            Criar conta
        </a>
    </p>
</div>
