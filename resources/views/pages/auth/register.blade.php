<div>
    <div class="mb-6 text-center">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">Criar uma conta</h1>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">Junte-se para compartilhar seus momentos</p>
    </div>

    <form wire:submit="register" class="space-y-4">
        <x-ui.input
            label="Nome"
            name="form.name"
            wire:model="form.name"
            :error="$errors->first('form.name')"
            required
            autofocus
            autocomplete="name"
        />

        <x-ui.input
            label="E-mail"
            name="form.email"
            type="email"
            wire:model="form.email"
            :error="$errors->first('form.email')"
            required
            autocomplete="email"
        />

        <x-ui.input
            label="Senha"
            name="form.password"
            type="password"
            wire:model="form.password"
            :error="$errors->first('form.password')"
            required
            autocomplete="new-password"
            hint="Mínimo de 8 caracteres"
        />

        <x-ui.input
            label="Confirmar senha"
            name="form.password_confirmation"
            type="password"
            wire:model="form.password_confirmation"
            :error="$errors->first('form.password_confirmation')"
            required
            autocomplete="new-password"
        />

        <x-ui.button type="submit" variant="primary" class="w-full">
            Criar conta
        </x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-[var(--color-text-muted)]">
        Já tem uma conta?
        <a href="{{ url('/login') }}" class="font-medium text-[var(--color-brand-via)] hover:underline" wire:navigate>
            Entrar
        </a>
    </p>
</div>
