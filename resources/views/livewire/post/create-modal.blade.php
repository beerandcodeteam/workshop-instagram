<div>
    <x-ui.modal wire:model="open" title="Criar publicação" maxWidth="lg">
        @if ($step === 'type')
            <div class="space-y-3">
                <p class="text-sm text-[var(--color-text-muted)]">
                    Escolha o tipo de publicação que deseja criar.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <button
                        type="button"
                        wire:click="selectType('text')"
                        class="flex flex-col items-center justify-center gap-2 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-6 text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] transition focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)]"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 6h16M4 12h16M4 18h10" />
                        </svg>
                        <span class="text-sm font-semibold">Texto</span>
                    </button>

                    <button
                        type="button"
                        wire:click="selectType('image')"
                        class="flex flex-col items-center justify-center gap-2 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-6 text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] transition focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)]"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <circle cx="8.5" cy="8.5" r="1.5" />
                            <path d="M21 15l-5-5L5 21" />
                        </svg>
                        <span class="text-sm font-semibold">Imagem</span>
                    </button>

                    <button
                        type="button"
                        wire:click="selectType('video')"
                        class="flex flex-col items-center justify-center gap-2 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-6 text-[var(--color-text)] hover:bg-[var(--color-neutral-100)] transition focus:outline-none focus:ring-2 focus:ring-[var(--color-brand-via)]"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polygon points="23 7 16 12 23 17 23 7" />
                            <rect x="1" y="5" width="15" height="14" rx="2" />
                        </svg>
                        <span class="text-sm font-semibold">Vídeo</span>
                    </button>
                </div>
            </div>
        @elseif ($step === 'text')
            <form wire:submit="submitText" class="space-y-4">
                <x-ui.textarea
                    label="Texto da publicação"
                    id="text-post-body"
                    wire:model="textForm.body"
                    rows="6"
                    required
                    :error="$errors->first('textForm.body')"
                    :counter="strlen($textForm->body) . '/2200'"
                />

                <div class="flex items-center justify-between gap-2">
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        wire:click="backToTypeSelection"
                    >
                        Voltar
                    </x-ui.button>

                    <x-ui.button type="submit" variant="primary" size="sm">
                        <span wire:loading.remove wire:target="submitText">Publicar</span>
                        <span wire:loading wire:target="submitText">Publicando...</span>
                    </x-ui.button>
                </div>
            </form>
        @elseif ($step === 'image')
            <form wire:submit="submitImages" class="space-y-4">
                <div class="flex flex-col gap-1">
                    <label for="image-post-files" class="text-sm font-medium text-[var(--color-text)]">
                        Imagens <span class="text-[var(--color-danger)]">*</span>
                    </label>
                    <input
                        type="file"
                        id="image-post-files"
                        wire:model="imageForm.images"
                        multiple
                        accept="image/jpeg,image/png,image/webp"
                        class="block w-full text-sm text-[var(--color-text)] file:mr-3 file:rounded-[var(--radius-sm)] file:border-0 file:bg-[var(--color-neutral-100)] file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-[var(--color-text)] hover:file:bg-[var(--color-neutral-200)]"
                    />
                    <p class="text-xs text-[var(--color-text-muted)]">
                        Até 10 imagens (jpg, png, webp).
                    </p>
                    @error('imageForm.images')
                        <p class="text-xs text-[var(--color-danger)]">{{ $message }}</p>
                    @enderror
                    @foreach ($errors->get('imageForm.images.*') as $messages)
                        @foreach ($messages as $message)
                            <p class="text-xs text-[var(--color-danger)]">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>

                <x-ui.textarea
                    label="Legenda (opcional)"
                    id="image-post-caption"
                    wire:model="imageForm.caption"
                    rows="3"
                    :error="$errors->first('imageForm.caption')"
                    :counter="strlen($imageForm->caption ?? '') . '/2200'"
                />

                <div wire:loading wire:target="imageForm.images" class="text-xs text-[var(--color-text-muted)]">
                    Enviando imagens...
                </div>

                <div class="flex items-center justify-between gap-2">
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="backToTypeSelection">
                        Voltar
                    </x-ui.button>

                    <x-ui.button type="submit" variant="primary" size="sm">
                        <span wire:loading.remove wire:target="submitImages">Publicar</span>
                        <span wire:loading wire:target="submitImages">Publicando...</span>
                    </x-ui.button>
                </div>
            </form>
        @elseif ($step === 'video')
            <form wire:submit="submitVideo" class="space-y-4">
                <div class="flex flex-col gap-1">
                    <label for="video-post-file" class="text-sm font-medium text-[var(--color-text)]">
                        Vídeo <span class="text-[var(--color-danger)]">*</span>
                    </label>
                    <input
                        type="file"
                        id="video-post-file"
                        wire:model="videoForm.video"
                        accept="video/mp4,video/quicktime,video/webm"
                        class="block w-full text-sm text-[var(--color-text)] file:mr-3 file:rounded-[var(--radius-sm)] file:border-0 file:bg-[var(--color-neutral-100)] file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-[var(--color-text)] hover:file:bg-[var(--color-neutral-200)]"
                    />
                    <p class="text-xs text-[var(--color-text-muted)]">
                        Formatos mp4, mov, webm. Até 100 MB e 60 segundos.
                    </p>
                    @error('videoForm.video')
                        <p class="text-xs text-[var(--color-danger)]">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.textarea
                    label="Legenda (opcional)"
                    id="video-post-caption"
                    wire:model="videoForm.caption"
                    rows="3"
                    :error="$errors->first('videoForm.caption')"
                    :counter="strlen($videoForm->caption ?? '') . '/2200'"
                />

                <div wire:loading wire:target="videoForm.video" class="text-xs text-[var(--color-text-muted)]">
                    Enviando vídeo...
                </div>

                <div class="flex items-center justify-between gap-2">
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="backToTypeSelection">
                        Voltar
                    </x-ui.button>

                    <x-ui.button type="submit" variant="primary" size="sm">
                        <span wire:loading.remove wire:target="submitVideo">Publicar</span>
                        <span wire:loading wire:target="submitVideo">Publicando...</span>
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>
</div>
