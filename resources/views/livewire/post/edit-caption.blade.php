<div>
    <button
        type="button"
        wire:click="openModal"
        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] focus:outline-none focus:bg-[var(--color-neutral-100)]"
    >
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
        </svg>
        <span>Editar legenda</span>
    </button>

    <x-ui.modal wire:model="open" title="Editar legenda" maxWidth="lg">
        <form wire:submit="save" class="space-y-4">
            <x-ui.textarea
                label="Legenda"
                id="edit-caption-body-{{ $post->id }}"
                wire:model="form.body"
                rows="6"
                required
                :error="$errors->first('form.body')"
                :counter="strlen($form->body) . '/2200'"
            />

            <div class="flex items-center justify-end gap-2">
                <x-ui.button type="button" variant="ghost" size="sm" wire:click="closeModal">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" variant="primary" size="sm">
                    <span wire:loading.remove wire:target="save">Salvar</span>
                    <span wire:loading wire:target="save">Salvando...</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
